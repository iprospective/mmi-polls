<?php
// Service Swaps : demandes de remplacement d'astreinte entre participant·e·s.
// Premier·ère qui accepte gagne ; tout le monde est notifié·e.
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/mailer.php';
require_once __DIR__ . '/../services/assignments.php';

/**
 * Candidat·e·s « par défaut » pour reprendre un créneau : tout·e·s celles
 * qui avaient voté yes ou maybe sur ce choice_id, à l'exclusion du
 * demandeur·euse. On garde même les personnes déjà sur l'autre rôle du
 * même créneau : elles peuvent monter d'un rang (suppléant·e devient
 * principal·e ; leur ancien rôle libère une case que le manager pourra
 * combler).
 */
function swap_candidate_pids(int $poll_id, int $choice_id, int $requester_pid): array {
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT DISTINCT v.participant_id
        FROM votes v
        JOIN participants p ON p.id = v.participant_id
        WHERE v.choice_id = ?
          AND v.value IN ('yes', 'maybe')
          AND p.poll_id = ?
          AND p.id != ?
        ORDER BY v.value DESC -- 'yes' avant 'maybe' (collation lexicographique)
    ");
    $stmt->execute([$choice_id, $poll_id, $requester_pid]);
    return array_map('intval', array_column($stmt->fetchAll(), 'participant_id'));
}

/**
 * Crée une demande de remplacement + tokens individuels + envoie les
 * emails d'invitation. Vérifie que l'astreinte appartient bien au
 * requester et qu'aucune autre demande open n'existe déjà sur (cid, role).
 *
 * @return int request_id
 */
function issue_swap_request(
    array $poll,
    int $requester_pid,
    int $choice_id,
    string $role,
    array $target_pids,
    string $message
): int {
    if (!in_array($role, ['primary', 'backup'], true)) {
        throw new InvalidArgumentException('Rôle invalide.');
    }
    $pdo = db();

    // Garde-fous : assignation existe ET appartient au requester ET cible un créneau du sondage.
    $check = $pdo->prepare("
        SELECT a.participant_id
        FROM assignments a
        JOIN poll_choices c ON c.id = a.choice_id
        JOIN poll_dates   d ON d.id = c.date_id
        WHERE a.choice_id = ? AND a.role = ? AND d.poll_id = ?
    ");
    $check->execute([$choice_id, $role, (int)$poll['id']]);
    $owner = $check->fetchColumn();
    if (!$owner || (int)$owner !== $requester_pid) {
        throw new RuntimeException('Cette astreinte n\'est plus à vous.');
    }

    // Pas de doublon de requête ouverte sur ce (cid, role).
    $dup = $pdo->prepare("
        SELECT id FROM swap_requests
        WHERE poll_id = ? AND choice_id = ? AND role = ? AND status = 'open'
    ");
    $dup->execute([(int)$poll['id'], $choice_id, $role]);
    if ($dup->fetch()) {
        throw new RuntimeException('Une demande est déjà en cours pour ce créneau.');
    }

    // Whitelist des cibles : doivent être candidat·e·s officiel·le·s.
    $allowed = array_flip(swap_candidate_pids((int)$poll['id'], $choice_id, $requester_pid));
    $clean_targets = array_values(array_unique(array_filter(
        array_map('intval', $target_pids),
        fn($p) => $p > 0 && isset($allowed[$p])
    )));
    if (!$clean_targets) {
        throw new RuntimeException('Aucun·e destinataire valide.');
    }

    $now = time();
    $pdo->beginTransaction();
    $ins_req = $pdo->prepare(
        "INSERT INTO swap_requests (poll_id, requester_pid, choice_id, role, status, message, created_at)
         VALUES (?, ?, ?, ?, 'open', ?, ?)"
    );
    $ins_req->execute([(int)$poll['id'], $requester_pid, $choice_id, $role, $message, $now]);
    $request_id = (int)$pdo->lastInsertId();

    $ins_tgt = $pdo->prepare(
        "INSERT INTO swap_request_targets (request_id, participant_id, token_hash, sent_at)
         VALUES (?, ?, ?, ?)"
    );
    $targets_with_tokens = [];
    foreach ($clean_targets as $pid) {
        $token = bin2hex(random_bytes(24));
        $hash  = hash('sha256', $token);
        $ins_tgt->execute([$request_id, $pid, $hash, $now]);
        $targets_with_tokens[] = ['pid' => $pid, 'token' => $token];
    }
    $pdo->commit();

    // Hors-transaction : envoi des emails (SMTP lent, ne doit pas bloquer la DB).
    $requester = swap_load_participant($requester_pid);
    $slot = swap_load_choice_label($choice_id);
    foreach ($targets_with_tokens as $t) {
        $target = swap_load_participant($t['pid']);
        if (!$target) continue;
        try {
            send_swap_invite_email($poll, $requester, $target, $slot, $role, $message, $t['token']);
        } catch (Throwable $e) {
            mail_log($target['email'], '[swap invite failed] ' . $e->getMessage(), '');
        }
    }

    return $request_id;
}

/**
 * Accepte une demande de manière atomique :
 *  - verrouille la requête (status='open')
 *  - vérifie que l'assignation d'origine appartient toujours au requester
 *  - si l'accepteur tenait l'autre rôle du même créneau, le libère
 *  - transfère l'astreinte (DELETE + INSERT)
 *  - ferme la requête
 *  - marque la réponse du target
 *  - bump assignments_updated_at pour requester ET accepteur
 *
 * @return array{ok:bool, reason?:string}
 */
function accept_swap_request(string $token): array {
    $pdo = db();
    $target = find_swap_target_by_token($token);
    if (!$target) {
        return ['ok' => false, 'reason' => 'not_found'];
    }
    $request_id  = (int)$target['request_id'];
    $accepter_pid = (int)$target['participant_id'];

    $pdo->beginTransaction();
    try {
        // Verrouille (SQLite : SELECT in transaction + UPDATE plus loin).
        $req_stmt = $pdo->prepare("SELECT * FROM swap_requests WHERE id = ?");
        $req_stmt->execute([$request_id]);
        $req = $req_stmt->fetch();
        if (!$req) {
            $pdo->rollBack();
            return ['ok' => false, 'reason' => 'not_found'];
        }
        if ($req['status'] !== 'open') {
            $pdo->rollBack();
            return ['ok' => false, 'reason' => $req['status']]; // 'taken' | 'cancelled' | 'expired'
        }

        // L'astreinte existe-t-elle encore et appartient-elle toujours au requester ?
        $own = $pdo->prepare("SELECT participant_id FROM assignments WHERE choice_id = ? AND role = ?");
        $own->execute([(int)$req['choice_id'], $req['role']]);
        $cur_owner = $own->fetchColumn();
        if (!$cur_owner || (int)$cur_owner !== (int)$req['requester_pid']) {
            // L'astreinte a été déplacée par le manager entre-temps : on expire.
            $up = $pdo->prepare("UPDATE swap_requests SET status='expired', closed_at=? WHERE id=? AND status='open'");
            $up->execute([time(), $request_id]);
            $pdo->commit();
            return ['ok' => false, 'reason' => 'expired'];
        }

        // Si l'accepteur tient l'autre rôle du même créneau : on le libère
        // (un·e participant·e ne peut pas être à la fois principal·e et
        // suppléant·e sur le même slot).
        $other_role = $req['role'] === 'primary' ? 'backup' : 'primary';
        $other = $pdo->prepare("SELECT participant_id FROM assignments WHERE choice_id = ? AND role = ?");
        $other->execute([(int)$req['choice_id'], $other_role]);
        $other_owner = (int)($other->fetchColumn() ?: 0);
        $freed_other_role = false;
        if ($other_owner === $accepter_pid) {
            $del_other = $pdo->prepare("DELETE FROM assignments WHERE choice_id = ? AND role = ?");
            $del_other->execute([(int)$req['choice_id'], $other_role]);
            $freed_other_role = true;
        }

        // Transfert : supprime puis insère (PK = (choice_id, role)).
        $del = $pdo->prepare("DELETE FROM assignments WHERE choice_id = ? AND role = ?");
        $del->execute([(int)$req['choice_id'], $req['role']]);
        $ins = $pdo->prepare("INSERT INTO assignments (choice_id, role, participant_id) VALUES (?, ?, ?)");
        $ins->execute([(int)$req['choice_id'], $req['role'], $accepter_pid]);

        // Ferme la requête de manière conditionnelle (concurrence : si une
        // autre transaction a déjà clos, on annule tout).
        $close = $pdo->prepare(
            "UPDATE swap_requests SET status='taken', taken_by_pid=?, closed_at=?
             WHERE id=? AND status='open'"
        );
        $close->execute([$accepter_pid, time(), $request_id]);
        if ($close->rowCount() === 0) {
            $pdo->rollBack();
            return ['ok' => false, 'reason' => 'race'];
        }

        // Marque la réponse de ce target.
        $mark = $pdo->prepare(
            "UPDATE swap_request_targets SET response='accept', responded_at=? WHERE id=?"
        );
        $mark->execute([time(), (int)$target['id']]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // Bump staleness pour requester + accepteur (sortir/entrer de la liste
    // de notifs envoyées).
    mark_participants_assignments_stale([(int)$req['requester_pid'], $accepter_pid]);

    return [
        'ok' => true,
        'request_id'       => $request_id,
        'freed_other_role' => !empty($freed_other_role),
    ];
}

/**
 * Décline (silencieux). Ne change pas l'état de la requête (les autres
 * targets peuvent encore accepter).
 */
function decline_swap_request(string $token): bool {
    $target = find_swap_target_by_token($token);
    if (!$target) return false;
    $upd = db()->prepare(
        "UPDATE swap_request_targets SET response='decline', responded_at=?
         WHERE id=? AND response IS NULL"
    );
    $upd->execute([time(), (int)$target['id']]);
    return true;
}

/**
 * Annulation par le requester (ou par un manager via admin override).
 * Ferme la requête si elle est encore open. Notifie les targets qui
 * n'avaient pas encore répondu pour qu'ils sachent que c'est inutile.
 */
function cancel_swap_request(int $request_id, string $cancelled_by_label): bool {
    $pdo = db();
    $req = swap_request_full($request_id);
    if (!$req) return false;
    if ($req['status'] !== 'open') return false;

    $upd = $pdo->prepare(
        "UPDATE swap_requests SET status='cancelled', closed_at=? WHERE id=? AND status='open'"
    );
    $upd->execute([time(), $request_id]);
    if ($upd->rowCount() === 0) return false;

    // Notifie les targets non-répondants pour éviter qu'ils découvrent
    // le pot aux roses en cliquant un lien périmé.
    foreach ($req['targets'] as $t) {
        if ($t['response'] !== null) continue;
        try {
            send_swap_cancelled_email($req, $t, $cancelled_by_label);
        } catch (Throwable $e) {
            mail_log($t['email'], '[swap cancel failed] ' . $e->getMessage(), '');
        }
    }
    return true;
}

/**
 * Toutes les demandes ouvertes pour un sondage (admin view).
 */
function list_open_swaps(int $poll_id): array {
    $stmt = db()->prepare("
        SELECT
          sr.*,
          p.name AS requester_name, p.email AS requester_email,
          d.day, c.label AS slot_label,
          (SELECT COUNT(*) FROM swap_request_targets WHERE request_id = sr.id) AS n_targets,
          (SELECT COUNT(*) FROM swap_request_targets WHERE request_id = sr.id AND response = 'decline') AS n_declines
        FROM swap_requests sr
        JOIN participants p ON p.id = sr.requester_pid
        JOIN poll_choices c ON c.id = sr.choice_id
        JOIN poll_dates   d ON d.id = c.date_id
        WHERE sr.poll_id = ? AND sr.status = 'open'
        ORDER BY d.day, c.sort_order, sr.created_at
    ");
    $stmt->execute([$poll_id]);
    return $stmt->fetchAll();
}

/**
 * Les demandes ouvertes lancées par ce participant (pour /me).
 */
function list_my_open_swaps(int $poll_id, int $requester_pid): array {
    $stmt = db()->prepare("
        SELECT sr.*, d.day, c.label AS slot_label,
          (SELECT COUNT(*) FROM swap_request_targets WHERE request_id = sr.id) AS n_targets,
          (SELECT COUNT(*) FROM swap_request_targets WHERE request_id = sr.id AND response = 'decline') AS n_declines
        FROM swap_requests sr
        JOIN poll_choices c ON c.id = sr.choice_id
        JOIN poll_dates   d ON d.id = c.date_id
        WHERE sr.poll_id = ? AND sr.requester_pid = ? AND sr.status = 'open'
        ORDER BY d.day, c.sort_order, sr.created_at
    ");
    $stmt->execute([$poll_id, $requester_pid]);
    return $stmt->fetchAll();
}

function find_swap_target_by_token(string $token): ?array {
    if ($token === '') return null;
    $hash = hash('sha256', $token);
    $stmt = db()->prepare("SELECT * FROM swap_request_targets WHERE token_hash = ?");
    $stmt->execute([$hash]);
    return $stmt->fetch() ?: null;
}

/**
 * Charge une requête avec toutes ses infos affichables (requester, créneau, targets).
 */
function swap_request_full(int $request_id): ?array {
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT sr.*,
          po.uuid AS poll_uuid, po.title AS poll_title, po.contact_email AS poll_contact,
          rq.name AS requester_name, rq.email AS requester_email,
          d.day, c.label AS slot_label
        FROM swap_requests sr
        JOIN polls         po ON po.id = sr.poll_id
        JOIN participants  rq ON rq.id = sr.requester_pid
        JOIN poll_choices  c  ON c.id  = sr.choice_id
        JOIN poll_dates    d  ON d.id  = c.date_id
        WHERE sr.id = ?
    ");
    $stmt->execute([$request_id]);
    $req = $stmt->fetch();
    if (!$req) return null;
    $tgt = $pdo->prepare("
        SELECT t.*, p.name, p.email
        FROM swap_request_targets t
        JOIN participants p ON p.id = t.participant_id
        WHERE t.request_id = ?
        ORDER BY p.name, p.email
    ");
    $tgt->execute([$request_id]);
    $req['targets'] = $tgt->fetchAll();
    return $req;
}

function swap_load_participant(int $pid): ?array {
    $stmt = db()->prepare("SELECT * FROM participants WHERE id = ?");
    $stmt->execute([$pid]);
    return $stmt->fetch() ?: null;
}

function swap_load_choice_label(int $choice_id): array {
    $stmt = db()->prepare("
        SELECT c.label, d.day
        FROM poll_choices c JOIN poll_dates d ON d.id = c.date_id
        WHERE c.id = ?
    ");
    $stmt->execute([$choice_id]);
    return $stmt->fetch() ?: ['label' => '?', 'day' => ''];
}

function swap_role_label(string $role): string {
    return $role === 'primary' ? 'Principal·e' : 'Suppléant·e';
}

function swap_slot_human(array $slot, string $role): string {
    return fmt_day((string)$slot['day']) . ' — ' . (string)$slot['label'] . ' (' . swap_role_label($role) . ')';
}

/* ---------- Emails ---------- */

function send_swap_invite_email(array $poll, array $requester, array $target, array $slot, string $role, string $message, string $token): void {
    $app_url = rtrim($GLOBALS['CONFIG']['app_url'], '/');
    $respond_url = $app_url . '/swap/' . urlencode($token);
    $r_name = $requester['name'] !== '' ? $requester['name'] : explode('@', $requester['email'])[0];
    $t_name = $target['name']    !== '' ? $target['name']    : explode('@', $target['email'])[0];
    $slot_h = swap_slot_human($slot, $role);

    $subject = '[mmidate] ' . $r_name . ' cherche un·e remplaçant·e — ' . $slot['label'] . ' du ' . fmt_day($slot['day']);
    $body  = "Bonjour $t_name,\n\n";
    $body .= "$r_name ne peut finalement pas assurer son astreinte :\n";
    $body .= "  • $slot_h\n\n";
    $body .= "Vous aviez indiqué être dispo (oui ou peut-être) sur ce créneau.\n";
    $body .= "Si vous pouvez prendre le relais, un clic suffit — premier·ère arrivé·e, premier·ère servi·e :\n\n";
    $body .= "$respond_url\n\n";
    if ($message !== '') {
        $body .= "Message de $r_name :\n---\n$message\n---\n\n";
    }
    $body .= "Sondage : « {$poll['title']} » — $app_url/p/{$poll['uuid']}\n";
    $body .= "Merci !\n";
    send_mail($target['email'], $subject, $body);
}

function send_swap_accepted_email_to_requester(array $req, array $accepter): void {
    $app_url = rtrim($GLOBALS['CONFIG']['app_url'], '/');
    $r_name = $req['requester_name'] !== '' ? $req['requester_name'] : explode('@', $req['requester_email'])[0];
    $a_name = $accepter['name']     !== '' ? $accepter['name']      : explode('@', $accepter['email'])[0];
    $slot_h = swap_slot_human(['day' => $req['day'], 'label' => $req['slot_label']], $req['role']);

    $subject = '[mmidate] ' . $a_name . ' reprend votre astreinte — ' . $req['slot_label'] . ' du ' . fmt_day($req['day']);
    $body  = "Bonjour $r_name,\n\n";
    $body .= "Bonne nouvelle : $a_name accepte de reprendre votre astreinte.\n";
    $body .= "  • $slot_h\n\n";
    $body .= "C'est officiel, votre planning est à jour côté mmidate.\n";
    $body .= "Pensez à mettre à jour votre agenda perso le cas échéant.\n\n";
    $body .= "Voir le sondage : $app_url/p/{$req['poll_uuid']}\n";
    $body .= "Merci !\n";
    send_mail($req['requester_email'], $subject, $body);
}

function send_swap_accepted_email_to_accepter(array $req, array $accepter, bool $freed_other_role): void {
    $app_url = rtrim($GLOBALS['CONFIG']['app_url'], '/');
    $a_name = $accepter['name']     !== '' ? $accepter['name']      : explode('@', $accepter['email'])[0];
    $r_name = $req['requester_name'] !== '' ? $req['requester_name'] : explode('@', $req['requester_email'])[0];
    $slot_h = swap_slot_human(['day' => $req['day'], 'label' => $req['slot_label']], $req['role']);
    $other_role_label = swap_role_label($req['role'] === 'primary' ? 'backup' : 'primary');

    $subject = '[mmidate] C\'est noté : vous reprenez l\'astreinte de ' . $r_name;
    $body  = "Bonjour $a_name,\n\n";
    $body .= "Merci d'avoir accepté l'astreinte de $r_name :\n";
    $body .= "  • $slot_h\n\n";
    if ($freed_other_role) {
        $body .= "Note : vous étiez $other_role_label sur le même créneau, ce rôle est libéré (impossible de tenir les deux).\n\n";
    }
    $body .= "Voir le sondage : $app_url/p/{$req['poll_uuid']}\n";
    $body .= "Merci pour votre coup de main !\n";
    send_mail($accepter['email'], $subject, $body);
}

function send_swap_too_late_email(array $req, array $target_row, string $accepter_label): void {
    $t_name = $target_row['name'] !== '' ? $target_row['name'] : explode('@', $target_row['email'])[0];
    $slot_h = swap_slot_human(['day' => $req['day'], 'label' => $req['slot_label']], $req['role']);
    $subject = '[mmidate] Trop tard, ' . $accepter_label . ' a déjà accepté';
    $body  = "Bonjour $t_name,\n\n";
    $body .= "Pas la peine de répondre à la demande de remplacement sur :\n";
    $body .= "  • $slot_h\n\n";
    $body .= "$accepter_label a accepté avant vous. Merci d'avoir été dispo !\n";
    send_mail($target_row['email'], $subject, $body);
}

function send_swap_cancelled_email(array $req, array $target_row, string $cancelled_by_label): void {
    $t_name = $target_row['name'] !== '' ? $target_row['name'] : explode('@', $target_row['email'])[0];
    $slot_h = swap_slot_human(['day' => $req['day'], 'label' => $req['slot_label']], $req['role']);
    $subject = '[mmidate] Demande de remplacement annulée';
    $body  = "Bonjour $t_name,\n\n";
    $body .= "La demande de remplacement pour le créneau suivant a été annulée par $cancelled_by_label :\n";
    $body .= "  • $slot_h\n\n";
    $body .= "Rien à faire de votre côté. Merci !\n";
    send_mail($target_row['email'], $subject, $body);
}

function send_swap_manager_summary(array $req, array $accepter, ?string $contact_email): void {
    if (!$contact_email || !filter_var($contact_email, FILTER_VALIDATE_EMAIL)) return;
    $app_url = rtrim($GLOBALS['CONFIG']['app_url'], '/');
    $r_name = $req['requester_name'] !== '' ? $req['requester_name'] : explode('@', $req['requester_email'])[0];
    $a_name = $accepter['name']      !== '' ? $accepter['name']      : explode('@', $accepter['email'])[0];
    $slot_h = swap_slot_human(['day' => $req['day'], 'label' => $req['slot_label']], $req['role']);

    $subject = '[mmidate] Échange d\'astreinte : ' . $r_name . ' → ' . $a_name;
    $body  = "Pour info, un échange a eu lieu sur le sondage « {$req['poll_title']} » :\n\n";
    $body .= "  • $slot_h\n";
    $body .= "  • Cédé par : $r_name <{$req['requester_email']}>\n";
    $body .= "  • Repris par : $a_name <{$accepter['email']}>\n\n";
    $body .= "Vue admin : $app_url/admin/polls/{$req['poll_uuid']}/assignments\n";
    send_mail($contact_email, $subject, $body);
}
