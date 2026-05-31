<?php
// Controller : gestion des participants (liste, création, édition,
// suppression, vue calendrier individuel).
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../services/polls.php';
require_once __DIR__ . '/../services/participants.php';
require_once __DIR__ . '/../services/assignments.php';
require_once __DIR__ . '/../services/geocoder.php';

function route_admin_participants_list(string $uuid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT
            p.id, p.name, p.email, p.created_at, p.votes_updated_at,
            p.phone, p.contact_method, p.hidden_in_public,
            COALESCE(SUM(CASE WHEN v.value='yes'   THEN 1 ELSE 0 END), 0) AS yes_count,
            COALESCE(SUM(CASE WHEN v.value='maybe' THEN 1 ELSE 0 END), 0) AS maybe_count,
            COALESCE(SUM(CASE WHEN v.value='no'    THEN 1 ELSE 0 END), 0) AS no_count,
            COALESCE((SELECT COUNT(*) FROM assignments a WHERE a.participant_id = p.id AND a.role='primary'), 0) AS primary_count,
            COALESCE((SELECT COUNT(*) FROM assignments a WHERE a.participant_id = p.id AND a.role='backup'),  0) AS backup_count,
            (SELECT n.status FROM notifications n WHERE n.poll_id = p.poll_id AND n.participant_id = p.id) AS notif_status,
            (SELECT n.responded_at FROM notifications n WHERE n.poll_id = p.poll_id AND n.participant_id = p.id) AS notif_responded_at
        FROM participants p
        LEFT JOIN votes v ON v.participant_id = p.id
        WHERE p.poll_id = ?
        GROUP BY p.id
        ORDER BY p.name, p.email
    ");
    $stmt->execute([$poll['id']]);
    $rows = $stmt->fetchAll();

    $total_choices = poll_total_choices((int)$poll['id']);

    render('admin/participants', [
        'page_title' => 'Participants — ' . $poll['title'],
        'poll' => $poll,
        'rows' => $rows,
        'total_choices' => $total_choices,
        'include_sortable' => true,
        'include_contact_toggles' => true,
    ]);
}

function route_admin_participant_calendar(string $uuid, string $pid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    $pdo  = db();
    $stmt = $pdo->prepare("SELECT * FROM participants WHERE id = ? AND poll_id = ?");
    $stmt->execute([(int)$pid, $poll['id']]);
    $participant = $stmt->fetch();
    if (!$participant) not_found();

    $assigns = assignments_for_participant((int)$poll['id'], (int)$participant['id']);
    $cnt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN value='yes'   THEN 1 ELSE 0 END) AS yes_count,
            SUM(CASE WHEN value='maybe' THEN 1 ELSE 0 END) AS maybe_count,
            SUM(CASE WHEN value='no'    THEN 1 ELSE 0 END) AS no_count
        FROM votes WHERE participant_id = ?
    ");
    $cnt->execute([$participant['id']]);
    $vote_counts = $cnt->fetch() ?: ['yes_count' => 0, 'maybe_count' => 0, 'no_count' => 0];

    render('admin/participant_calendar', [
        'page_title' => 'Calendrier — ' . ($participant['name'] !== '' ? $participant['name'] : $participant['email']),
        'poll' => $poll,
        'participant' => $participant,
        'assigns' => $assigns,
        'vote_counts' => $vote_counts,
    ]);
}

function route_admin_create_participant(string $uuid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    $name  = trim((string)($_POST['name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_set('err', 'Email invalide.');
        redirect('/admin/polls/' . $uuid . '/participants');
    }
    $pdo = db();
    $check = $pdo->prepare("SELECT id FROM participants WHERE poll_id = ? AND email = ?");
    $check->execute([$poll['id'], $email]);
    if ($row = $check->fetch()) {
        flash_set('err', 'Un participant avec cet email existe déjà.');
        redirect('/admin/polls/' . $uuid . '/participants/' . (int)$row['id']);
    }
    $ins = $pdo->prepare("INSERT INTO participants (poll_id, email, name, created_at) VALUES (?, ?, ?, ?)");
    $ins->execute([$poll['id'], $email, $name, time()]);
    $new_id = (int)$pdo->lastInsertId();
    flash_set('ok', 'Participant ajouté. Saisissez maintenant ses disponibilités.');
    redirect('/admin/polls/' . $uuid . '/participants/' . $new_id);
}

function route_admin_edit_participant(string $uuid, string $pid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    $pdo = db();
    $p = $pdo->prepare("SELECT * FROM participants WHERE id = ? AND poll_id = ?");
    $p->execute([(int)$pid, $poll['id']]);
    $participant = $p->fetch();
    if (!$participant) not_found();
    $dates = poll_structure((int)$poll['id']);
    $v = $pdo->prepare("SELECT choice_id, value FROM votes WHERE participant_id = ?");
    $v->execute([$participant['id']]);
    $myvotes = [];
    foreach ($v as $row) $myvotes[(int)$row['choice_id']] = $row['value'];
    render('admin/participant', [
        'page_title' => 'Édition — ' . ($participant['name'] !== '' ? $participant['name'] : $participant['email']),
        'poll' => $poll,
        'dates' => $dates,
        'participant' => $participant,
        'myvotes' => $myvotes,
    ]);
}

function route_admin_update_participant(string $uuid, string $pid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    $pdo = db();
    $p = $pdo->prepare("SELECT * FROM participants WHERE id = ? AND poll_id = ?");
    $p->execute([(int)$pid, $poll['id']]);
    $participant = $p->fetch();
    if (!$participant) not_found();

    $name  = trim((string)($_POST['name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $phone = sanitize_phone((string)($_POST['phone'] ?? ''));
    $cm_input = $_POST['contact_method'] ?? [];
    if (!is_array($cm_input)) $cm_input = [$cm_input];
    $cm_valid = array_values(array_unique(array_intersect($cm_input, array_keys(contact_methods()))));
    sort($cm_valid);
    $contact_method = implode(',', $cm_valid);

    $address = trim((string)($_POST['address'] ?? ''));
    $lat = $participant['latitude']  ?? null;
    $lng = $participant['longitude'] ?? null;
    if ($address !== (string)($participant['address'] ?? '')) {
        if ($address === '') { $lat = null; $lng = null; }
        else {
            $geo = geocode($address);
            if ($geo) { $lat = $geo['lat']; $lng = $geo['lng']; }
            else      { $lat = null; $lng = null; }
        }
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_set('err', 'Email invalide.');
        redirect('/admin/polls/' . $uuid . '/participants/' . (int)$pid);
    }
    // Detect email collision with another participant of the same poll.
    $coll = $pdo->prepare("SELECT id FROM participants WHERE poll_id = ? AND email = ? AND id != ?");
    $coll->execute([$poll['id'], $email, $participant['id']]);
    if ($coll->fetch()) {
        flash_set('err', 'Un autre participant du sondage utilise déjà cet email.');
        redirect('/admin/polls/' . $uuid . '/participants/' . (int)$pid);
    }

    $votes = $_POST['votes'] ?? [];
    if (!is_array($votes)) $votes = [];
    $valid = $pdo->prepare("
        SELECT c.id FROM poll_choices c
        JOIN poll_dates d ON d.id = c.date_id
        WHERE d.poll_id = ?
    ");
    $valid->execute([$poll['id']]);
    $valid_ids = array_flip(array_map('intval', array_column($valid->fetchAll(), 'id')));

    // Compare votes avant/après pour ne bumper votes_updated_at que si
    // les votes ont effectivement changé (cohérent avec /me participant).
    $new_votes = [];
    foreach ($votes as $cid => $val) {
        $cid = (int)$cid;
        if (!isset($valid_ids[$cid])) continue;
        if (!in_array($val, ['yes', 'no', 'maybe'], true)) continue;
        $new_votes[$cid] = $val;
    }
    ksort($new_votes);
    $cur_v = $pdo->prepare("SELECT choice_id, value FROM votes WHERE participant_id = ?");
    $cur_v->execute([$participant['id']]);
    $current_votes = [];
    foreach ($cur_v as $row) $current_votes[(int)$row['choice_id']] = $row['value'];
    ksort($current_votes);
    $votes_changed = ($current_votes !== $new_votes);

    $pdo->beginTransaction();
    if ($votes_changed) {
        $upd = $pdo->prepare("UPDATE participants SET name = ?, email = ?, phone = ?, contact_method = ?, address = ?, latitude = ?, longitude = ?, votes_updated_at = ? WHERE id = ?");
        $upd->execute([$name, $email, $phone, $contact_method, $address, $lat, $lng, time(), $participant['id']]);
        $del = $pdo->prepare("DELETE FROM votes WHERE participant_id = ?");
        $del->execute([$participant['id']]);
        $ins = $pdo->prepare("INSERT INTO votes (participant_id, choice_id, value) VALUES (?, ?, ?)");
        foreach ($new_votes as $cid => $val) {
            $ins->execute([$participant['id'], $cid, $val]);
        }
    } else {
        $upd = $pdo->prepare("UPDATE participants SET name = ?, email = ?, phone = ?, contact_method = ?, address = ?, latitude = ?, longitude = ? WHERE id = ?");
        $upd->execute([$name, $email, $phone, $contact_method, $address, $lat, $lng, $participant['id']]);
    }
    $pdo->commit();
    flash_set('ok', $votes_changed ? 'Participant mis à jour (votes inclus).' : 'Profil mis à jour (votes inchangés).');
    redirect('/admin/polls/' . $uuid . '/participants/' . (int)$pid);
}

function route_admin_delete_participant(string $uuid, string $pid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    $stmt = db()->prepare("DELETE FROM participants WHERE id = ? AND poll_id = ?");
    $stmt->execute([(int)$pid, $poll['id']]);
    flash_set('ok', 'Participant supprimé.');
    redirect('/admin/polls/' . $uuid . '/participants');
}

function route_admin_remind_participant(string $uuid, string $pid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, name, email FROM participants WHERE id = ? AND poll_id = ?");
    $stmt->execute([(int)$pid, $poll['id']]);
    $p = $stmt->fetch();
    if (!$p) not_found();
    require_once __DIR__ . '/../lib/mailer.php';
    send_reminder_email($poll, $p);
    flash_set('ok', 'Rappel envoyé à ' . $p['email'] . '.');
    redirect('/admin/polls/' . $uuid . '/participants');
}

function route_admin_remind_non_responders(string $uuid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    $pdo = db();
    // Non-répondants = participants sans aucun vote.
    $stmt = $pdo->prepare("
        SELECT p.id, p.name, p.email
        FROM participants p
        LEFT JOIN votes v ON v.participant_id = p.id
        WHERE p.poll_id = ?
        GROUP BY p.id
        HAVING COUNT(v.choice_id) = 0
    ");
    $stmt->execute([$poll['id']]);
    $orphans = $stmt->fetchAll();
    if (!$orphans) {
        flash_set('ok', 'Aucun non-répondant à relancer.');
        redirect('/admin/polls/' . $uuid . '/participants');
    }
    require_once __DIR__ . '/../lib/mailer.php';
    $sent = 0;
    foreach ($orphans as $p) {
        try { send_reminder_email($poll, $p); $sent++; }
        catch (Throwable $e) { mail_log($p['email'], '[reminder failed] ' . $e->getMessage(), ''); }
    }
    flash_set('ok', "Rappel envoyé à $sent non-répondant(s).");
    redirect('/admin/polls/' . $uuid . '/participants');
}

function send_reminder_email(array $poll, array $participant): void {
    require_once __DIR__ . '/../lib/auth.php';
    $token = issue_magic_link((int)$poll['id'], strtolower($participant['email']));
    $app_url = rtrim($GLOBALS['CONFIG']['app_url'], '/');
    $link    = $app_url . '/p/' . $poll['uuid'] . '/auth?token=' . urlencode($token);
    $name = $participant['name'] !== '' ? $participant['name'] : explode('@', $participant['email'])[0];
    $subject = '[mmidate] Rappel : indiquez vos disponibilités pour « ' . $poll['title'] . ' »';
    $body  = "Bonjour $name,\n\n";
    $body .= "Petit rappel pour le sondage « {$poll['title']} » : pensez à indiquer vos disponibilités.\n\n";
    $body .= "Lien de connexion direct :\n$link\n\n";
    $body .= "Ce lien est valable " . (int)($GLOBALS['CONFIG']['magic_link_ttl'] / 60) . " minutes.\n\n";
    $body .= "Merci !\n";
    send_mail($participant['email'], $subject, $body);
}

function route_admin_toggle_participant_contact(string $uuid, string $pid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    header('Content-Type: application/json; charset=UTF-8');

    $method = (string)($_POST['method'] ?? '');
    if (!array_key_exists($method, contact_methods())) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_method']);
        exit;
    }

    $pdo = db();
    $stmt = $pdo->prepare("SELECT contact_method FROM participants WHERE id = ? AND poll_id = ?");
    $stmt->execute([(int)$pid, $poll['id']]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'not_found']);
        exit;
    }

    $cur = parse_contact_methods($row['contact_method'] ?? '');
    if (in_array($method, $cur, true)) {
        $cur = array_values(array_filter($cur, fn($m) => $m !== $method));
    } else {
        $cur[] = $method;
    }
    sort($cur);
    $new_csv = implode(',', $cur);

    $upd = $pdo->prepare("UPDATE participants SET contact_method = ? WHERE id = ?");
    $upd->execute([$new_csv, (int)$pid]);

    echo json_encode(['ok' => true, 'methods' => $cur]);
    exit;
}

function route_admin_toggle_participant_visibility(string $uuid, string $pid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    $pdo = db();
    $stmt = $pdo->prepare("SELECT hidden_in_public, name, email FROM participants WHERE id = ? AND poll_id = ?");
    $stmt->execute([(int)$pid, $poll['id']]);
    $p = $stmt->fetch();
    if (!$p) not_found();
    $new = $p['hidden_in_public'] ? 0 : 1;
    $upd = $pdo->prepare("UPDATE participants SET hidden_in_public = ? WHERE id = ? AND poll_id = ?");
    $upd->execute([$new, (int)$pid, $poll['id']]);
    $who = $p['name'] !== '' ? $p['name'] : $p['email'];
    flash_set('ok', $new
        ? "$who est désormais masqué·e dans la vue publique."
        : "$who est de nouveau visible dans la vue publique.");
    redirect('/admin/polls/' . $uuid . '/participants');
}
