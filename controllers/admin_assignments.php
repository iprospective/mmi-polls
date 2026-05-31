<?php
// Controller : page Astreintes + save manuel + auto-fill + clear.
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../services/polls.php';
require_once __DIR__ . '/../services/participants.php';
require_once __DIR__ . '/../services/votes.php';
require_once __DIR__ . '/../services/assignments.php';
require_once __DIR__ . '/../services/notifications.php';
require_once __DIR__ . '/../services/auto_fill.php';

function route_admin_assignments(string $uuid): void {
    require_admin();
    $poll = find_poll($uuid);
    $dates        = poll_structure((int)$poll['id']);
    $participants = poll_participants((int)$poll['id']);
    $votes        = poll_votes_map((int)$poll['id']);
    $assigns      = poll_assignments_map((int)$poll['id']);
    $notifs       = poll_notifications_status((int)$poll['id']);

    // Participants ayant au moins une assignation (pour le formulaire de notification).
    $assigned_ids = [];
    foreach ($assigns as $by_role) {
        foreach ($by_role as $pid) $assigned_ids[(int)$pid] = true;
    }
    $assigned_participants = array_values(array_filter($participants,
        fn($p) => isset($assigned_ids[(int)$p['id']])));

    render('admin/assignments', [
        'page_title' => 'Astreintes — ' . $poll['title'],
        'poll' => $poll,
        'dates' => $dates,
        'participants' => $participants,
        'assigned_participants' => $assigned_participants,
        'votes' => $votes,
        'assigns' => $assigns,
        'notifs' => $notifs,
        'include_assignments' => true,
    ]);
}

function route_admin_save_assignments(string $uuid): void {
    require_admin();
    $poll = find_poll($uuid);
    $input = $_POST['assignments'] ?? [];
    if (!is_array($input)) $input = [];

    $pdo = db();

    // Whitelist des choix valides pour ce sondage.
    $vc = $pdo->prepare("
        SELECT c.id FROM poll_choices c
        JOIN poll_dates d ON d.id = c.date_id
        WHERE d.poll_id = ?
    ");
    $vc->execute([$poll['id']]);
    $valid_choice_ids = array_flip(array_map('intval', array_column($vc->fetchAll(), 'id')));

    // Whitelist des participants du sondage.
    $vp = $pdo->prepare("SELECT id FROM participants WHERE poll_id = ?");
    $vp->execute([$poll['id']]);
    $valid_part_ids = array_flip(array_map('intval', array_column($vp->fetchAll(), 'id')));

    $pdo->beginTransaction();
    $del = $pdo->prepare("
        DELETE FROM assignments
        WHERE choice_id IN (
            SELECT c.id FROM poll_choices c
            JOIN poll_dates d ON d.id = c.date_id
            WHERE d.poll_id = ?
        )
    ");
    $del->execute([$poll['id']]);

    $ins = $pdo->prepare("INSERT INTO assignments (choice_id, role, participant_id) VALUES (?, ?, ?)");
    foreach ($input as $cid => $role_map) {
        $cid = (int)$cid;
        if (!isset($valid_choice_ids[$cid])) continue;
        if (!is_array($role_map)) continue;

        $prim = (int)($role_map['primary'] ?? 0);
        $back = (int)($role_map['backup']  ?? 0);
        if ($prim > 0 && !isset($valid_part_ids[$prim])) $prim = 0;
        if ($back > 0 && !isset($valid_part_ids[$back])) $back = 0;
        // Anti-doublon : interdit la même personne aux deux rôles.
        // Si conflit, on garde le principal et on retire le suppléant.
        if ($prim > 0 && $prim === $back) $back = 0;

        if ($prim > 0) $ins->execute([$cid, 'primary', $prim]);
        if ($back > 0) $ins->execute([$cid, 'backup',  $back]);
    }
    $pdo->commit();
    flash_set('ok', 'Astreintes enregistrées.');
    redirect('/admin/polls/' . $uuid . '/assignments');
}

function route_admin_auto_fill_assignments(string $uuid): void {
    require_admin();
    $poll = find_poll($uuid);
    $res = run_auto_fill_assignments((int)$poll['id']);
    $total = $res['inserted_p'] + $res['inserted_b'];
    if ($total > 0) {
        flash_set('ok', "Remplissage automatique : $total créneaux remplis "
            . "({$res['inserted_p']} principaux, {$res['inserted_b']} suppléants).");
    } else {
        flash_set('ok', 'Aucun créneau à remplir.');
    }
    redirect('/admin/polls/' . $uuid . '/assignments');
}

function route_admin_clear_assignments(string $uuid): void {
    require_admin();
    $poll = find_poll($uuid);
    $stmt = db()->prepare("
        DELETE FROM assignments
        WHERE choice_id IN (
            SELECT c.id FROM poll_choices c
            JOIN poll_dates d ON d.id = c.date_id
            WHERE d.poll_id = ?
        )
    ");
    $stmt->execute([$poll['id']]);
    flash_set('ok', 'Toutes les astreintes ont été supprimées.');
    redirect('/admin/polls/' . $uuid . '/assignments');
}
