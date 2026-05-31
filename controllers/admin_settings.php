<?php
// Controller : page Paramètres d'un sondage.
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../services/polls.php';
require_once __DIR__ . '/../services/managers.php';
require_once __DIR__ . '/../services/poll_managers.php';

function route_admin_settings(string $uuid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    $managers     = list_poll_managers((int)$poll['id']);
    // Pour l'admin : dropdown de tous les managers actifs absents du sondage.
    $all_active   = is_admin() ? list_managers('active') : [];
    $present_ids  = array_column($managers, 'id');
    $available    = array_values(array_filter($all_active,
        fn($m) => !in_array((int)$m['id'], $present_ids, true)));
    render('admin/settings', [
        'page_title' => 'Paramètres — ' . $poll['title'],
        'poll' => $poll,
        'managers' => $managers,
        'available_managers' => $available,
        'include_editor' => true,
    ]);
}
