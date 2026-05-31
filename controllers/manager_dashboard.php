<?php
// Controller : dashboard du manager (liste de ses propres sondages + création).
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../services/managers.php';
require_once __DIR__ . '/../services/poll_managers.php';

function route_manager_dashboard(): void {
    require_manager();
    $mid = current_manager_id();
    $polls = list_polls_managed_by((int)$mid);
    $manager = find_manager_by_id((int)$mid);
    render('manager/list', [
        'page_title' => 'Mes sondages',
        'polls' => $polls,
        'manager' => $manager,
        'include_editor' => true,
    ]);
}
