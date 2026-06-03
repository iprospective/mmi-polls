<?php
// Controller : ajout / retrait de managers sur un sondage.
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/mailer.php';
require_once __DIR__ . '/../services/polls.php';
require_once __DIR__ . '/../services/managers.php';
require_once __DIR__ . '/../services/poll_managers.php';
require_once __DIR__ . '/../services/activity_log.php';

function route_poll_add_manager(string $uuid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_set('err', 'Email invalide.');
        redirect('/admin/polls/' . $uuid . '/settings');
    }
    $m = find_manager_by_email($email);
    if (!$m) {
        flash_set('err', 'Aucun compte manager actif ne correspond à cet email. La personne doit d\'abord s\'inscrire à /register et être validée.');
        redirect('/admin/polls/' . $uuid . '/settings');
    }
    if ($m['status'] !== 'active') {
        flash_set('err', 'Le compte de ' . $m['email'] . ' est ' . $m['status'] . ', il faut d\'abord le valider.');
        redirect('/admin/polls/' . $uuid . '/settings');
    }
    $by_admin = is_admin();
    $inviter  = is_admin() ? null : current_manager_id();
    $added = add_poll_manager((int)$poll['id'], (int)$m['id'], $by_admin, $inviter);
    if (!$added) {
        flash_set('err', $m['email'] . ' est déjà manager de ce sondage.');
    } else {
        flash_set('ok', $m['email'] . ' a maintenant accès à ce sondage.');
        notify_manager_added_to_poll($m, $poll);
        log_activity((int)$poll['id'], 'poll_manager_add', ['target' => $m['email']]);
    }
    redirect('/admin/polls/' . $uuid . '/settings');
}

function route_poll_remove_manager(string $uuid, string $mid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    // Garde-fou : un manager ne peut pas se retirer du dernier sondage où
    // il est s'il n'y a plus que lui — sinon plus personne (sauf admin) pour
    // y accéder. Idem : un manager ne devrait pas pouvoir se retirer lui-même
    // si ça laisse le sondage sans manager (laisser à l'admin).
    $current_mgrs = list_poll_managers((int)$poll['id']);
    if (count($current_mgrs) <= 1 && !is_admin()) {
        flash_set('err', 'Vous êtes le·la dernier·ère manager : un·e admin global·e doit retirer le sondage.');
        redirect('/admin/polls/' . $uuid . '/settings');
    }
    $m = find_manager_by_id((int)$mid);
    remove_poll_manager((int)$poll['id'], (int)$mid);
    log_activity((int)$poll['id'], 'poll_manager_remove', ['target' => $m['email'] ?? '#' . $mid]);
    flash_set('ok', ($m['email'] ?? 'Manager') . ' retiré du sondage.');
    redirect('/admin/polls/' . $uuid . '/settings');
}

function notify_manager_added_to_poll(array $manager, array $poll): void {
    $app_url = rtrim($GLOBALS['CONFIG']['app_url'], '/');
    $name = $manager['name'] !== '' ? $manager['name'] : $manager['email'];
    $subject = '[MMIrelay] Vous avez été ajouté·e au sondage « ' . $poll['title'] . ' »';
    $body  = "Bonjour $name,\n\n";
    $body .= "Vous avez été ajouté·e comme manager du sondage « {$poll['title']} ».\n\n";
    $body .= "Vous pouvez maintenant l'administrer ici :\n$app_url/admin/polls/{$poll['uuid']}\n\n";
    $body .= "Bonne organisation !\n";
    try { send_mail($manager['email'], $subject, $body); }
    catch (Throwable $e) { mail_log($manager['email'], '[poll add notif failed] ' . $e->getMessage(), $body); }
}
