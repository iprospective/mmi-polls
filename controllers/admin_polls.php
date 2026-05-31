<?php
// Controller : liste des sondages, création / vue / mise à jour / suppression.
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/html_sanitize.php';
require_once __DIR__ . '/../services/polls.php';
require_once __DIR__ . '/../services/participants.php';
require_once __DIR__ . '/../services/votes.php';
require_once __DIR__ . '/../services/assignments.php';

function route_admin_list(): void {
    require_admin();
    $polls = db()->query("
        SELECT p.*, m.email AS manager_email, m.name AS manager_name
        FROM polls p
        LEFT JOIN managers m ON m.id = p.manager_id
        ORDER BY p.created_at DESC
    ")->fetchAll();
    render('admin/list', ['page_title' => 'Sondages', 'polls' => $polls, 'include_editor' => true]);
}

function route_admin_create_poll(): void {
    if (!is_admin() && !is_manager()) redirect('/login');
    $title = trim((string)($_POST['title'] ?? ''));
    $desc  = sanitize_html((string)($_POST['description'] ?? ''));
    $back  = is_admin() ? '/admin' : '/manager';
    if ($title === '') {
        flash_set('err', 'Titre obligatoire.');
        redirect($back);
    }
    $uuid = uuid_v4();
    $manager_id = is_admin() ? null : current_manager_id();
    $stmt = db()->prepare("INSERT INTO polls (uuid, title, description, created_at, manager_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$uuid, $title, $desc, time(), $manager_id]);
    redirect('/admin/polls/' . $uuid);
}

function route_admin_poll(string $uuid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    $dates = poll_structure((int)$poll['id']);
    $participants = poll_participants((int)$poll['id']);
    $votes = poll_votes_map((int)$poll['id']);
    $assigns = poll_assignments_map((int)$poll['id']);
    render('admin/poll', [
        'page_title' => $poll['title'],
        'poll' => $poll,
        'dates' => $dates,
        'participants' => $participants,
        'votes' => $votes,
        'assigns' => $assigns,
    ]);
}

function route_admin_update_poll(string $uuid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    $title = trim((string)($_POST['title'] ?? ''));
    $desc  = sanitize_html((string)($_POST['description'] ?? ''));
    if ($title === '') {
        flash_set('err', 'Titre obligatoire.');
        redirect('/admin/polls/' . $uuid . '/settings');
    }
    $stmt = db()->prepare("UPDATE polls SET title = ?, description = ? WHERE id = ?");
    $stmt->execute([$title, $desc, $poll['id']]);
    flash_set('ok', 'Sondage mis à jour.');
    redirect('/admin/polls/' . $uuid . '/settings');
}

function route_admin_delete_poll(string $uuid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    $stmt = db()->prepare("DELETE FROM polls WHERE id = ?");
    $stmt->execute([$poll['id']]);
    flash_set('ok', 'Sondage supprimé.');
    redirect('/admin');
}
