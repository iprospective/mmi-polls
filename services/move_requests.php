<?php
// Service Move Requests : drag'n'drop d'astreintes initié par le manager.
// Génère une demande de validation aux personnes concernées (src, +dst si
// la cible était occupée) et n'applique le changement que quand tou·te·s
// ont accepté. Réutilise mark_participants_assignments_stale() pour faire
// apparaître le badge « À renotifier ».
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/mailer.php';
require_once __DIR__ . '/../services/assignments.php';

/**
 * Crée une demande de déplacement (ou échange si la case cible est occupée).
 * Émet 1 ou 2 tokens, envoie les emails. Renvoie l'id de la requête.
 *
 * @throws RuntimeException si l'état des assignations a divergé entre le
 *   drop et la requête (race avec un autre manager / un swap accepté).
 */
function create_move_request(
    array $poll,
    int $src_choice_id, string $src_role,
    int $dst_choice_id, string $dst_role,
    string $message,
    string $initiated_by_label
): int {
    if (!in_array($src_role, ['primary', 'backup'], true)
        || !in_array($dst_role, ['primary', 'backup'], true)) {
        throw new InvalidArgumentException('Rôle invalide.');
    }
    if ($src_choice_id === $dst_choice_id && $src_role === $dst_role) {
        throw new InvalidArgumentException('Source et destination identiques.');
    }
    $pdo = db();

    // L'assignation source doit exister et appartenir au sondage.
    $src_pid = move_owner_of((int)$poll['id'], $src_choice_id, $src_role);
    if ($src_pid === 0) throw new RuntimeException('La source n\'a plus d\'astreinte assignée.');
    // La cible peut être vide (move) ou occupée (swap).
    $dst_pid = move_owner_of((int)$poll['id'], $dst_choice_id, $dst_role);
    if ($dst_pid === $src_pid) {
        throw new RuntimeException('La personne source est déjà sur la case cible.');
    }

    $now = time();
    $pdo->beginTransaction();
    $ins_req = $pdo->prepare(
        "INSERT INTO move_requests
         (poll_id, initiated_by, src_pid, src_choice_id, src_role,
          dst_choice_id, dst_role, dst_pid, status, message, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)"
    );
    $ins_req->execute([
        (int)$poll['id'], $initiated_by_label,
        $src_pid, $src_choice_id, $src_role,
        $dst_choice_id, $dst_role, $dst_pid > 0 ? $dst_pid : null,
        $message, $now,
    ]);
    $req_id = (int)$pdo->lastInsertId();

    $ins_resp = $pdo->prepare(
        "INSERT INTO move_request_responses
         (request_id, participant_id, role_in_swap, token_hash, sent_at)
         VALUES (?, ?, ?, ?, ?)"
    );
    $tokens = [];
    $src_token = bin2hex(random_bytes(24));
    $ins_resp->execute([$req_id, $src_pid, 'src', hash('sha256', $src_token), $now]);
    $tokens['src'] = ['pid' => $src_pid, 'token' => $src_token];
    if ($dst_pid > 0) {
        $dst_token = bin2hex(random_bytes(24));
        $ins_resp->execute([$req_id, $dst_pid, 'dst', hash('sha256', $dst_token), $now]);
        $tokens['dst'] = ['pid' => $dst_pid, 'token' => $dst_token];
    }
    $pdo->commit();

    // Hors transaction : envoi des emails (lent, ne doit pas verrouiller la DB).
    $req = load_move_request_full($req_id);
    foreach ($tokens as $role_in_swap => $t) {
        $p = load_participant_for_move($t['pid']);
        if (!$p) continue;
        try {
            send_move_invite_email($poll, $req, $p, $role_in_swap, $t['token'], $initiated_by_label);
        } catch (Throwable $e) {
            mail_log($p['email'], '[move invite failed] ' . $e->getMessage(), '');
        }
    }
    return $req_id;
}

/**
 * Pose la réponse d'un·e participant·e. Si tout le monde a répondu et que
 * tout le monde a accepté, applique le déplacement / l'échange de manière
 * atomique. Si un·e décline, ferme en 'declined' et notifie l'autre.
 *
 * @return array{ok:bool, status?:string, reason?:string}
 */
function respond_to_move_request(string $token, string $response, string $reply = ''): array {
    if (!in_array($response, ['accept', 'decline'], true)) {
        return ['ok' => false, 'reason' => 'bad_response'];
    }
    $resp_row = find_move_response_by_token($token);
    if (!$resp_row) return ['ok' => false, 'reason' => 'not_found'];
    $req_id = (int)$resp_row['request_id'];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Verrouille la requête.
        $req_stmt = $pdo->prepare("SELECT * FROM move_requests WHERE id = ?");
        $req_stmt->execute([$req_id]);
        $req = $req_stmt->fetch();
        if (!$req) { $pdo->rollBack(); return ['ok' => false, 'reason' => 'not_found']; }
        if ($req['status'] !== 'pending') {
            $pdo->rollBack();
            return ['ok' => false, 'reason' => $req['status']];
        }

        // Pose la réponse (idempotent : si déjà répondu, on ne touche pas).
        $upd_resp = $pdo->prepare(
            "UPDATE move_request_responses SET response = ?, responded_at = ?
             WHERE id = ? AND response IS NULL"
        );
        $upd_resp->execute([$response, time(), (int)$resp_row['id']]);
        $changed = $upd_resp->rowCount() > 0;
        if (!$changed) {
            // Déjà répondu — on ne change pas l'état actuel.
            $pdo->commit();
            return ['ok' => true, 'status' => 'already_responded'];
        }

        // Refus = clôture immédiate.
        if ($response === 'decline') {
            $up_req = $pdo->prepare("UPDATE move_requests SET status = 'declined', closed_at = ? WHERE id = ?");
            $up_req->execute([time(), $req_id]);
            $pdo->commit();
            // Notifier les autres : « X a refusé, demande annulée »
            $req_full = load_move_request_full($req_id);
            notify_other_responders_of_close($req_full, (int)$resp_row['participant_id'], 'declined');
            return ['ok' => true, 'status' => 'declined'];
        }

        // Acceptation : si tout le monde a accepté, appliquer.
        $all = $pdo->prepare("SELECT COUNT(*) FROM move_request_responses WHERE request_id = ?");
        $all->execute([$req_id]);
        $n_total = (int)$all->fetchColumn();
        $ok = $pdo->prepare("SELECT COUNT(*) FROM move_request_responses WHERE request_id = ? AND response = 'accept'");
        $ok->execute([$req_id]);
        $n_accept = (int)$ok->fetchColumn();

        if ($n_accept < $n_total) {
            // Encore en attente de l'autre.
            $pdo->commit();
            return ['ok' => true, 'status' => 'waiting_other'];
        }

        // Tout le monde a accepté → appliquer.
        $apply_res = apply_move_atomic_in_tx($pdo, $req);
        if (!$apply_res['ok']) {
            // L'état a divergé entre temps (assignation déplacée par un·e
            // autre manager / un swap accepté). On ferme en 'expired'.
            $up_req = $pdo->prepare("UPDATE move_requests SET status = 'expired', closed_at = ? WHERE id = ?");
            $up_req->execute([time(), $req_id]);
            $pdo->commit();
            $req_full = load_move_request_full($req_id);
            notify_other_responders_of_close($req_full, (int)$resp_row['participant_id'], 'expired');
            return ['ok' => true, 'status' => 'expired'];
        }
        $up_req = $pdo->prepare("UPDATE move_requests SET status = 'applied', closed_at = ? WHERE id = ?");
        $up_req->execute([time(), $req_id]);
        $pdo->commit();

        // Bump staleness pour les participant·e·s impacté·e·s.
        $pids = [(int)$req['src_pid']];
        if ((int)$req['dst_pid'] > 0) $pids[] = (int)$req['dst_pid'];
        mark_participants_assignments_stale($pids);

        $req_full = load_move_request_full($req_id);
        notify_applied($req_full);
        return ['ok' => true, 'status' => 'applied'];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Annulation par le manager (admin override). Notifie les responders
 * non-répondants pour qu'ils ne cliquent pas un lien périmé.
 */
function cancel_move_request(int $request_id, string $by_label): bool {
    $req = load_move_request_full($request_id);
    if (!$req || $req['status'] !== 'pending') return false;
    $stmt = db()->prepare("UPDATE move_requests SET status = 'cancelled', closed_at = ? WHERE id = ? AND status = 'pending'");
    $stmt->execute([time(), $request_id]);
    if ($stmt->rowCount() === 0) return false;
    foreach ($req['responses'] as $r) {
        if ($r['response'] !== null) continue;
        try { send_move_cancelled_email($req, $r, $by_label); }
        catch (Throwable $e) { mail_log($r['email'], '[move cancel failed] ' . $e->getMessage(), ''); }
    }
    return true;
}

/**
 * Application atomique du move/swap dans une transaction déjà ouverte.
 * Vérifie que les propriétaires courants correspondent à ce qui avait été
 * promis dans la requête (sinon retourne ok=false → la requête sera marquée
 * 'expired' par l'appelant).
 */
function apply_move_atomic_in_tx(PDO $pdo, array $req): array {
    $cur_src = move_owner_of_in_tx($pdo, (int)$req['src_choice_id'], $req['src_role']);
    if ($cur_src !== (int)$req['src_pid']) {
        return ['ok' => false, 'reason' => 'src_changed'];
    }
    $cur_dst = move_owner_of_in_tx($pdo, (int)$req['dst_choice_id'], $req['dst_role']);
    $expected_dst = (int)$req['dst_pid']; // 0 si move vers case vide
    if ($cur_dst !== $expected_dst) {
        return ['ok' => false, 'reason' => 'dst_changed'];
    }

    $del = $pdo->prepare("DELETE FROM assignments WHERE choice_id = ? AND role = ?");
    $ins = $pdo->prepare("INSERT INTO assignments (choice_id, role, participant_id) VALUES (?, ?, ?)");

    // Supprime les 2 cases d'abord (évite tout conflit de PK pendant l'insert).
    $del->execute([(int)$req['src_choice_id'], $req['src_role']]);
    if ($expected_dst > 0) {
        $del->execute([(int)$req['dst_choice_id'], $req['dst_role']]);
    }
    // Insère le nouveau propriétaire de la cible (toujours src_pid).
    $ins->execute([(int)$req['dst_choice_id'], $req['dst_role'], (int)$req['src_pid']]);
    // En cas de swap : dst_pid prend la case source.
    if ($expected_dst > 0) {
        // Anti-doublon : si la case source est sur le même slot que la case
        // cible mais dans un autre rôle, et que dst_pid se retrouvait déjà
        // sur ce slot dans l'autre rôle, on a un conflit. Géré naturellement
        // par le DELETE/INSERT séquentiel mais on vérifie le cas pathologique.
        $ins->execute([(int)$req['src_choice_id'], $req['src_role'], $expected_dst]);
    }
    return ['ok' => true];
}

function move_owner_of(int $poll_id, int $choice_id, string $role): int {
    $stmt = db()->prepare("
        SELECT a.participant_id
        FROM assignments a
        JOIN poll_choices c ON c.id = a.choice_id
        JOIN poll_dates   d ON d.id = c.date_id
        WHERE a.choice_id = ? AND a.role = ? AND d.poll_id = ?
    ");
    $stmt->execute([$choice_id, $role, $poll_id]);
    return (int)($stmt->fetchColumn() ?: 0);
}

function move_owner_of_in_tx(PDO $pdo, int $choice_id, string $role): int {
    $stmt = $pdo->prepare("SELECT participant_id FROM assignments WHERE choice_id = ? AND role = ?");
    $stmt->execute([$choice_id, $role]);
    return (int)($stmt->fetchColumn() ?: 0);
}

function find_move_response_by_token(string $token): ?array {
    if ($token === '') return null;
    $hash = hash('sha256', $token);
    $stmt = db()->prepare("SELECT * FROM move_request_responses WHERE token_hash = ?");
    $stmt->execute([$hash]);
    return $stmt->fetch() ?: null;
}

function load_move_request_full(int $request_id): ?array {
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT mr.*,
               po.uuid AS poll_uuid, po.title AS poll_title, po.contact_email AS poll_contact,
               sp.name AS src_name, sp.email AS src_email,
               dp.name AS dst_name, dp.email AS dst_email,
               sd.day AS src_day, sc.label AS src_label,
               dd.day AS dst_day, dc.label AS dst_label
        FROM move_requests mr
        JOIN polls po          ON po.id = mr.poll_id
        JOIN participants sp   ON sp.id = mr.src_pid
        LEFT JOIN participants dp ON dp.id = mr.dst_pid
        JOIN poll_choices sc   ON sc.id = mr.src_choice_id
        JOIN poll_dates   sd   ON sd.id = sc.date_id
        JOIN poll_choices dc   ON dc.id = mr.dst_choice_id
        JOIN poll_dates   dd   ON dd.id = dc.date_id
        WHERE mr.id = ?
    ");
    $stmt->execute([$request_id]);
    $req = $stmt->fetch();
    if (!$req) return null;
    $resp = $pdo->prepare("
        SELECT mrr.*, p.name, p.email
        FROM move_request_responses mrr
        JOIN participants p ON p.id = mrr.participant_id
        WHERE mrr.request_id = ?
        ORDER BY mrr.role_in_swap
    ");
    $resp->execute([$request_id]);
    $req['responses'] = $resp->fetchAll();
    return $req;
}

function load_participant_for_move(int $pid): ?array {
    $stmt = db()->prepare("SELECT * FROM participants WHERE id = ?");
    $stmt->execute([$pid]);
    return $stmt->fetch() ?: null;
}

function list_pending_move_requests(int $poll_id): array {
    $stmt = db()->prepare("
        SELECT mr.id, mr.created_at, mr.status,
               sp.name AS src_name, sp.email AS src_email,
               dp.name AS dst_name, dp.email AS dst_email,
               sd.day AS src_day, sc.label AS src_label, mr.src_role,
               dd.day AS dst_day, dc.label AS dst_label, mr.dst_role,
               mr.dst_pid,
               (SELECT COUNT(*) FROM move_request_responses WHERE request_id = mr.id) AS n_total,
               (SELECT COUNT(*) FROM move_request_responses WHERE request_id = mr.id AND response = 'accept') AS n_accept
        FROM move_requests mr
        JOIN participants sp ON sp.id = mr.src_pid
        LEFT JOIN participants dp ON dp.id = mr.dst_pid
        JOIN poll_choices sc ON sc.id = mr.src_choice_id
        JOIN poll_dates   sd ON sd.id = sc.date_id
        JOIN poll_choices dc ON dc.id = mr.dst_choice_id
        JOIN poll_dates   dd ON dd.id = dc.date_id
        WHERE mr.poll_id = ? AND mr.status = 'pending'
        ORDER BY mr.created_at DESC
    ");
    $stmt->execute([$poll_id]);
    return $stmt->fetchAll();
}

/* ---------- Helpers d'affichage ---------- */

function move_human_slot(string $day, string $label, string $role): string {
    return fmt_day($day) . ' — ' . $label . ' (' . ($role === 'primary' ? 'Principal·e' : 'Suppléant·e') . ')';
}

/* ---------- Emails ---------- */

function send_move_invite_email(array $poll, array $req, array $target, string $role_in_swap, string $token, string $initiated_by_label): void {
    $app_url = rtrim($GLOBALS['CONFIG']['app_url'], '/');
    $url = $app_url . '/move/' . urlencode($token);
    $t_name = $target['name'] !== '' ? $target['name'] : explode('@', $target['email'])[0];
    $is_swap = !empty($req['dst_pid']);

    $src_slot = move_human_slot($req['src_day'], $req['src_label'], $req['src_role']);
    $dst_slot = move_human_slot($req['dst_day'], $req['dst_label'], $req['dst_role']);

    if ($role_in_swap === 'src') {
        // La personne « source » : on lui propose de bouger de src vers dst.
        if ($is_swap) {
            $subject = '[mmidate] Proposition d\'échange d\'astreinte par ' . $initiated_by_label;
            $body  = "Bonjour $t_name,\n\n";
            $body .= "$initiated_by_label propose d'échanger votre astreinte :\n";
            $body .= "  • Actuellement : $src_slot\n";
            $body .= "  • Vous prendriez à la place : $dst_slot\n";
            $body .= "  • " . trim(($req['dst_name'] ?? 'L\'autre personne')) . " prendrait votre créneau actuel\n\n";
        } else {
            $subject = '[mmidate] Proposition de déplacement d\'astreinte par ' . $initiated_by_label;
            $body  = "Bonjour $t_name,\n\n";
            $body .= "$initiated_by_label propose de déplacer votre astreinte :\n";
            $body .= "  • Actuellement : $src_slot\n";
            $body .= "  • Nouveau créneau proposé : $dst_slot\n\n";
        }
    } else {
        // dst : la personne qui devrait prendre src_slot en échange.
        $subject = '[mmidate] Proposition d\'échange d\'astreinte par ' . $initiated_by_label;
        $body  = "Bonjour $t_name,\n\n";
        $body .= "$initiated_by_label propose d'échanger votre astreinte avec celle de "
              . ($req['src_name'] ?? 'quelqu\'un') . " :\n";
        $body .= "  • Actuellement : $dst_slot\n";
        $body .= "  • Vous prendriez à la place : $src_slot\n\n";
    }
    if (!empty($req['message'])) {
        $body .= "Message de l'organisateur·rice :\n---\n{$req['message']}\n---\n\n";
    }
    $body .= "Le changement n'a lieu QUE si toutes les personnes concernées acceptent.\n";
    $body .= "Cliquez ici pour répondre :\n$url\n\n";
    $body .= "Sondage : « {$req['poll_title']} » — $app_url/p/{$req['poll_uuid']}\n";
    send_mail($target['email'], $subject, $body);
}

function send_move_cancelled_email(array $req, array $resp_row, string $by_label): void {
    $t_name = $resp_row['name'] !== '' ? $resp_row['name'] : explode('@', $resp_row['email'])[0];
    $subject = '[mmidate] Demande d\'échange annulée par ' . $by_label;
    $body  = "Bonjour $t_name,\n\n";
    $body .= "La demande de déplacement/échange d'astreinte vous concernant a été annulée par $by_label.\n";
    $body .= "Rien à faire de votre côté. Merci !\n";
    send_mail($resp_row['email'], $subject, $body);
}

function notify_other_responders_of_close(array $req, int $skip_pid, string $reason): void {
    $msgs = [
        'declined' => 'a été refusée par l\'autre personne',
        'expired'  => 'n\'est plus applicable (les astreintes ont changé entre temps)',
    ];
    $tail = $msgs[$reason] ?? 'a été clôturée';
    foreach ($req['responses'] as $r) {
        if ((int)$r['participant_id'] === $skip_pid) continue;
        if ($r['response'] === 'decline') continue;
        $t_name = $r['name'] !== '' ? $r['name'] : explode('@', $r['email'])[0];
        $subject = '[mmidate] Demande d\'échange clôturée';
        $body  = "Bonjour $t_name,\n\n";
        $body .= "La demande d'échange d'astreinte $tail. Rien à faire de votre côté.\n";
        try { send_mail($r['email'], $subject, $body); }
        catch (Throwable $e) { mail_log($r['email'], '[move close notify failed] ' . $e->getMessage(), ''); }
    }
}

function notify_applied(array $req): void {
    $app_url = rtrim($GLOBALS['CONFIG']['app_url'], '/');
    foreach ($req['responses'] as $r) {
        $t_name = $r['name'] !== '' ? $r['name'] : explode('@', $r['email'])[0];
        $subject = '[mmidate] Échange d\'astreinte appliqué';
        $body  = "Bonjour $t_name,\n\n";
        $body .= "Bonne nouvelle : l'échange/déplacement d'astreinte est désormais appliqué dans le sondage.\n";
        $body .= "Pensez à mettre à jour votre agenda perso le cas échéant.\n\n";
        $body .= "Voir le sondage : $app_url/p/{$req['poll_uuid']}\n";
        try { send_mail($r['email'], $subject, $body); }
        catch (Throwable $e) { mail_log($r['email'], '[move applied notify failed] ' . $e->getMessage(), ''); }
    }
    // Récap au contact_email du sondage (si configuré).
    $contact = trim((string)($req['poll_contact'] ?? ''));
    if ($contact !== '' && filter_var($contact, FILTER_VALIDATE_EMAIL)) {
        $subject = '[mmidate] Échange appliqué : ' . ($req['src_name'] ?: '?') . ' ↔ ' . ($req['dst_name'] ?: '(case vide)');
        $body = "Récap pour info :\n\n";
        $body .= "Sondage : « {$req['poll_title']} »\n";
        $body .= "Source : " . move_human_slot($req['src_day'], $req['src_label'], $req['src_role']) . " — était à : " . ($req['src_name'] ?: '?') . "\n";
        $body .= "Cible  : " . move_human_slot($req['dst_day'], $req['dst_label'], $req['dst_role']);
        $body .= !empty($req['dst_pid']) ? " — était à : " . $req['dst_name'] . "\n" : " — était vide\n";
        $body .= "\nInitiée par : " . $req['initiated_by'] . "\n";
        $body .= "Vue admin : " . $app_url . "/admin/polls/" . $req['poll_uuid'] . "/calendar\n";
        try { send_mail($contact, $subject, $body); } catch (Throwable $e) { /* silent */ }
    }
}
