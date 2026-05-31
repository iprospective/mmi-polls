<?php
// Controller : journal d'activité d'un sondage.
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../services/polls.php';
require_once __DIR__ . '/../services/activity_log.php';

function route_admin_activity(string $uuid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    $events = list_activity((int)$poll['id'], 500);
    render('admin/activity', [
        'page_title' => 'Activité — ' . $poll['title'],
        'poll' => $poll,
        'events' => $events,
    ]);
}
