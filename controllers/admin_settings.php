<?php
// Controller : page Paramètres d'un sondage.
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../services/polls.php';

function route_admin_settings(string $uuid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    render('admin/settings', [
        'page_title' => 'Paramètres — ' . $poll['title'],
        'poll' => $poll,
        'include_editor' => true,
    ]);
}
