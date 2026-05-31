<?php
// Service Auto-fill : algorithme de remplissage automatique des astreintes.
// Round-robin équitable basé sur usage_pct = count / capacité (crédits Oui + ½ Peut-être).
// 4 tiers (50/75/90 %) classés en priorité avec un boost low-credit en tier 0.
require_once __DIR__ . '/../lib/db.php';

/**
 * Lance l'algo de remplissage auto pour un sondage. N'écrase pas les
 * assignations existantes, INSERT seulement ce qui manque.
 *
 * @return array{inserted_p:int, inserted_b:int}
 */
function run_auto_fill_assignments(int $poll_id): array {
    $pdo = db();

    // 1. État existant.
    $assigns_stmt = $pdo->prepare("
        SELECT a.choice_id, a.role, a.participant_id, d.day
        FROM assignments a
        JOIN poll_choices c ON c.id = a.choice_id
        JOIN poll_dates   d ON d.id = c.date_id
        WHERE d.poll_id = ?
    ");
    $assigns_stmt->execute([$poll_id]);
    $existing = [];
    $counts   = [];
    $last_ts  = [];
    foreach ($assigns_stmt as $r) {
        $cid = (int)$r['choice_id'];
        $pid = (int)$r['participant_id'];
        $existing[$cid][$r['role']] = $pid;
        $counts[$pid] = ($counts[$pid] ?? 0) + 1;
        $ts = strtotime($r['day']) ?: 0;
        if (!isset($last_ts[$pid]) || $ts > $last_ts[$pid]) $last_ts[$pid] = $ts;
    }

    $choices_stmt = $pdo->prepare("
        SELECT c.id AS choice_id, c.label, d.day, c.sort_order AS c_order
        FROM poll_choices c
        JOIN poll_dates d ON d.id = c.date_id
        WHERE d.poll_id = ?
    ");
    $choices_stmt->execute([$poll_id]);
    $choices = $choices_stmt->fetchAll();

    $yes_by_choice = [];
    $maybe_by_choice = [];
    $yes_credits   = [];
    $maybe_credits = [];
    $votes_stmt = $pdo->prepare("
        SELECT v.choice_id, v.participant_id, v.value
        FROM votes v
        JOIN poll_choices c ON c.id = v.choice_id
        JOIN poll_dates   d ON d.id = c.date_id
        WHERE d.poll_id = ?
    ");
    $votes_stmt->execute([$poll_id]);
    foreach ($votes_stmt as $r) {
        $cid = (int)$r['choice_id'];
        $pid = (int)$r['participant_id'];
        if ($r['value'] === 'yes') {
            $yes_by_choice[$cid][] = $pid;
            $yes_credits[$pid] = ($yes_credits[$pid] ?? 0) + 1;
        } elseif ($r['value'] === 'maybe') {
            $maybe_by_choice[$cid][] = $pid;
            $maybe_credits[$pid] = ($maybe_credits[$pid] ?? 0) + 1;
        }
    }

    // 2. Ordre des créneaux : priorité de libellé puis chronologique.
    $priority = $GLOBALS['CONFIG']['auto_fill']['priority']
        ?? ['Nuit', 'Soirée', 'Journée'];
    $rank_of = [];
    foreach ($priority as $i => $label) $rank_of[mb_strtolower($label)] = $i;
    $label_rank = function (string $label) use ($rank_of) {
        return $rank_of[mb_strtolower($label)] ?? 999;
    };
    usort($choices, function ($a, $b) use ($label_rank) {
        $ra = $label_rank($a['label']);
        $rb = $label_rank($b['label']);
        if ($ra !== $rb) return $ra - $rb;
        if ($a['day'] !== $b['day']) return strcmp($a['day'], $b['day']);
        return (int)$a['c_order'] - (int)$b['c_order'];
    });

    // Capacité effective = crédits Oui + 0.5 × Peut-être (mini 1 pour
    // éviter une division par zéro chez les voteurs "maybe-only").
    $capacity_of = static function (int $pid, array $yes_credits, array $maybe_credits): float {
        $cap = ($yes_credits[$pid] ?? 0) + 0.5 * ($maybe_credits[$pid] ?? 0);
        return $cap > 0 ? $cap : 1.0;
    };

    // 3. Algo : tous les principaux d'abord, puis tous les suppléants.
    //    Au sein d'un tier, tri par usage_pct ASC.
    //    /!\ La closure usort est créée DANS la boucle pour capturer
    //    un snapshot frais de $counts et $last_ts à chaque pick.
    $new_assigns = [];
    foreach (['primary', 'backup'] as $role) {
        $other = $role === 'primary' ? 'backup' : 'primary';
        foreach ($choices as $c) {
            $cid = (int)$c['choice_id'];
            if (isset($existing[$cid][$role])) continue;
            $blocked = $existing[$cid][$other] ?? ($new_assigns[$cid][$other] ?? 0);
            $today_ts = strtotime($c['day']) ?: 0;

            $pick = null;
            foreach ([$yes_by_choice[$cid] ?? [], $maybe_by_choice[$cid] ?? []] as $pool) {
                $cands = array_values(array_filter($pool, fn($p) => (int)$p !== (int)$blocked));
                if (empty($cands)) continue;

                $by_tier = [0 => [], 1 => [], 2 => [], 3 => []];
                foreach ($cands as $pid) {
                    $cap = $capacity_of((int)$pid, $yes_credits, $maybe_credits);
                    $usage = ($counts[$pid] ?? 0) / $cap;
                    $tier = $usage < 0.50 ? 0 : ($usage < 0.75 ? 1 : ($usage < 0.90 ? 2 : 3));
                    $by_tier[$tier][] = (int)$pid;
                }

                foreach ([0, 1, 2, 3] as $t) {
                    if (empty($by_tier[$t])) continue;
                    $list = $by_tier[$t];
                    usort($list, function ($a, $b)
                          use ($t, $counts, $last_ts, $yes_credits, $maybe_credits, $today_ts, $capacity_of) {
                        $ua = ($counts[$a] ?? 0) / $capacity_of($a, $yes_credits, $maybe_credits);
                        $ub = ($counts[$b] ?? 0) / $capacity_of($b, $yes_credits, $maybe_credits);
                        if (abs($ua - $ub) > 1e-9) return $ua <=> $ub;
                        $ya = $yes_credits[$a] ?? 0;
                        $yb = $yes_credits[$b] ?? 0;
                        if ($t === 0 && $ya !== $yb) return $ya - $yb;
                        $la = isset($last_ts[$a]) ? $today_ts - $last_ts[$a] : PHP_INT_MAX;
                        $lb = isset($last_ts[$b]) ? $today_ts - $last_ts[$b] : PHP_INT_MAX;
                        if ($la !== $lb) return $lb <=> $la;
                        if ($ya !== $yb) return $ya - $yb;
                        return $a - $b;
                    });
                    $pick = (int)$list[0];
                    break 2;
                }
            }

            if ($pick === null) continue;
            $new_assigns[$cid][$role] = $pick;
            $counts[$pick] = ($counts[$pick] ?? 0) + 1;
            $last_ts[$pick] = max($last_ts[$pick] ?? 0, $today_ts);
        }
    }

    // 4. Persistance.
    $inserted_p = 0;
    $inserted_b = 0;
    if ($new_assigns) {
        $pdo->beginTransaction();
        $ins = $pdo->prepare("INSERT INTO assignments (choice_id, role, participant_id) VALUES (?, ?, ?)");
        foreach ($new_assigns as $cid => $by_role) {
            foreach ($by_role as $role => $pid) {
                try {
                    $ins->execute([$cid, $role, $pid]);
                    if ($role === 'primary') $inserted_p++; else $inserted_b++;
                } catch (Throwable $e) {
                    // PK clash improbable (existing déjà filtré), on ignore.
                }
            }
        }
        $pdo->commit();
    }
    return ['inserted_p' => $inserted_p, 'inserted_b' => $inserted_b];
}
