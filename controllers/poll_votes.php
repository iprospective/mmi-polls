<?php
// Controller : page "Mes choix" du participant + save/delete de ses votes.
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../services/polls.php';
require_once __DIR__ . '/../services/assignments.php';

function route_poll_me(string $uuid): void {
    $poll = find_poll($uuid);
    $auth = participant_session($uuid);
    if (!$auth) redirect('/p/' . $uuid . '/login');
    $pdo = db();
    $p = $pdo->prepare("SELECT * FROM participants WHERE id = ? AND poll_id = ?");
    $p->execute([$auth['participant_id'], $poll['id']]);
    $participant = $p->fetch();
    if (!$participant) {
        participant_logout($uuid);
        redirect('/p/' . $uuid . '/login');
    }
    $dates = poll_structure((int)$poll['id']);
    $myvotes = [];
    $v = $pdo->prepare("SELECT choice_id, value FROM votes WHERE participant_id = ?");
    $v->execute([$participant['id']]);
    foreach ($v as $row) $myvotes[(int)$row['choice_id']] = $row['value'];
    $my_assigns = assignments_for_participant((int)$poll['id'], (int)$participant['id']);
    render('poll/me', [
        'page_title' => 'Mes choix — ' . $poll['title'],
        'poll' => $poll,
        'dates' => $dates,
        'participant' => $participant,
        'myvotes' => $myvotes,
        'my_assigns' => $my_assigns,
    ]);
}

function route_poll_save_votes(string $uuid): void {
    $poll = find_poll($uuid);
    if (poll_is_closed($poll)) {
        flash_set('err', 'Ce sondage est clos, vos disponibilités ne sont plus modifiables.');
        redirect('/p/' . $uuid . '/me');
    }
    $auth = participant_session($uuid);
    if (!$auth) redirect('/p/' . $uuid . '/login');
    $pdo = db();
    $p = $pdo->prepare("SELECT * FROM participants WHERE id = ? AND poll_id = ?");
    $p->execute([$auth['participant_id'], $poll['id']]);
    $participant = $p->fetch();
    if (!$participant) {
        participant_logout($uuid);
        redirect('/p/' . $uuid . '/login');
    }
    $name = trim((string)($_POST['name'] ?? ''));
    $phone = sanitize_phone((string)($_POST['phone'] ?? ''));
    $cm_input = $_POST['contact_method'] ?? [];
    if (!is_array($cm_input)) $cm_input = [$cm_input];
    $cm_valid = array_values(array_unique(array_intersect($cm_input, array_keys(contact_methods()))));
    sort($cm_valid);
    $contact_method = implode(',', $cm_valid);
    $votes = $_POST['votes'] ?? [];
    if (!is_array($votes)) $votes = [];

    // Restrict choice_ids to this poll.
    $valid = $pdo->prepare("
        SELECT c.id FROM poll_choices c
        JOIN poll_dates d ON d.id = c.date_id
        WHERE d.poll_id = ?
    ");
    $valid->execute([$poll['id']]);
    $valid_ids = array_flip(array_map('intval', array_column($valid->fetchAll(), 'id')));

    $pdo->beginTransaction();
    $upd = $pdo->prepare("UPDATE participants SET name = ?, phone = ?, contact_method = ?, votes_updated_at = ? WHERE id = ?");
    $upd->execute([$name, $phone, $contact_method, time(), $participant['id']]);
    $del = $pdo->prepare("DELETE FROM votes WHERE participant_id = ?");
    $del->execute([$participant['id']]);
    $ins = $pdo->prepare("INSERT INTO votes (participant_id, choice_id, value) VALUES (?, ?, ?)");
    foreach ($votes as $cid => $val) {
        $cid = (int)$cid;
        if (!isset($valid_ids[$cid])) continue;
        if (!in_array($val, ['yes', 'no', 'maybe'], true)) continue;
        $ins->execute([$participant['id'], $cid, $val]);
    }
    $pdo->commit();
    flash_set('ok', 'Choix enregistrés.');
    redirect('/p/' . $uuid . '/me');
}

function route_poll_delete_votes(string $uuid): void {
    $poll = find_poll($uuid);
    $auth = participant_session($uuid);
    if (!$auth) redirect('/p/' . $uuid . '/login');
    $stmt = db()->prepare("DELETE FROM participants WHERE id = ? AND poll_id = ?");
    $stmt->execute([$auth['participant_id'], $poll['id']]);
    participant_logout($uuid);
    flash_set('ok', 'Vos choix ont été supprimés.');
    redirect('/p/' . $uuid);
}
