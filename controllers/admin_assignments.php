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
require_once __DIR__ . '/../services/activity_log.php';
require_once __DIR__ . '/../services/swaps.php';

function route_admin_assignments(string $uuid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
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

    $open_swaps = list_open_swaps((int)$poll['id']);

    render('admin/assignments', [
        'page_title' => 'Astreintes — ' . $poll['title'],
        'poll' => $poll,
        'dates' => $dates,
        'participants' => $participants,
        'assigned_participants' => $assigned_participants,
        'votes' => $votes,
        'assigns' => $assigns,
        'notifs' => $notifs,
        'open_swaps' => $open_swaps,
        'include_assignments' => true,
    ]);
}

function route_admin_save_assignments(string $uuid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
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

    // Snapshot AVANT pour diff (qui a perdu/gagné une astreinte).
    $before = poll_assignments_map((int)$poll['id']);

    // Normalise le POST en map[cid][role] = pid (après validation).
    $after = [];
    foreach ($input as $cid => $role_map) {
        $cid = (int)$cid;
        if (!isset($valid_choice_ids[$cid])) continue;
        if (!is_array($role_map)) continue;
        $prim = (int)($role_map['primary'] ?? 0);
        $back = (int)($role_map['backup']  ?? 0);
        if ($prim > 0 && !isset($valid_part_ids[$prim])) $prim = 0;
        if ($back > 0 && !isset($valid_part_ids[$back])) $back = 0;
        if ($prim > 0 && $prim === $back) $back = 0;
        if ($prim > 0) $after[$cid]['primary'] = $prim;
        if ($back > 0) $after[$cid]['backup']  = $back;
    }

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
    foreach ($after as $cid => $by_role) {
        foreach ($by_role as $role => $pid) {
            $ins->execute([$cid, $role, $pid]);
        }
    }
    $pdo->commit();

    // Diff before/after : tout participant dont l'ensemble (cid, role) change
    // doit être marqué stale (côté ancien et côté nouveau).
    $stale_pids = [];
    $cids = array_unique(array_merge(array_keys($before), array_keys($after)));
    foreach ($cids as $cid) {
        foreach (['primary', 'backup'] as $role) {
            $old = (int)($before[$cid][$role] ?? 0);
            $new = (int)($after[$cid][$role]  ?? 0);
            if ($old !== $new) {
                if ($old) $stale_pids[$old] = true;
                if ($new) $stale_pids[$new] = true;
            }
        }
    }
    if ($stale_pids) {
        mark_participants_assignments_stale(array_keys($stale_pids));
    }

    log_activity((int)$poll['id'], 'assign_save');
    flash_set('ok', 'Astreintes enregistrées.');
    redirect('/admin/polls/' . $uuid . '/assignments');
}

function route_admin_auto_fill_assignments(string $uuid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    $res = run_auto_fill_assignments((int)$poll['id']);
    $total = $res['inserted_p'] + $res['inserted_b'];
    if (!empty($res['affected_pids'])) {
        mark_participants_assignments_stale($res['affected_pids']);
    }
    log_activity((int)$poll['id'], 'assign_autofill', [
        'target'  => "+{$res['inserted_p']} principaux, +{$res['inserted_b']} suppléants",
        'payload' => $res,
    ]);
    if ($total > 0) {
        flash_set('ok', "Remplissage automatique : $total créneaux remplis "
            . "({$res['inserted_p']} principaux, {$res['inserted_b']} suppléants).");
    } else {
        flash_set('ok', 'Aucun créneau à remplir.');
    }
    redirect('/admin/polls/' . $uuid . '/assignments');
}

function route_admin_clear_assignments(string $uuid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    $affected = poll_assigned_participant_ids((int)$poll['id']);
    $stmt = db()->prepare("
        DELETE FROM assignments
        WHERE choice_id IN (
            SELECT c.id FROM poll_choices c
            JOIN poll_dates d ON d.id = c.date_id
            WHERE d.poll_id = ?
        )
    ");
    $stmt->execute([$poll['id']]);
    if ($affected) mark_participants_assignments_stale($affected);
    log_activity((int)$poll['id'], 'assign_clear');
    flash_set('ok', 'Toutes les astreintes ont été supprimées.');
    redirect('/admin/polls/' . $uuid . '/assignments');
}
