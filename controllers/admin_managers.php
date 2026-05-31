<?php
// Controller : admin — gestion des comptes manager (validation, rejet).
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/mailer.php';
require_once __DIR__ . '/../services/managers.php';

function route_admin_managers(): void {
    require_admin();
    $pending  = list_managers('pending');
    $active   = list_managers('active');
    $rejected = list_managers('rejected');
    render('admin/managers', [
        'page_title' => 'Comptes manager',
        'pending'    => $pending,
        'active'     => $active,
        'rejected'   => $rejected,
    ]);
}

function route_admin_validate_manager(string $id): void {
    require_admin();
    $m = find_manager_by_id((int)$id);
    if (!$m) not_found();
    validate_manager((int)$id);
    notify_manager_validated($m);
    flash_set('ok', 'Compte de ' . $m['email'] . ' validé.');
    redirect('/admin/managers');
}

function route_admin_reject_manager(string $id): void {
    require_admin();
    $m = find_manager_by_id((int)$id);
    if (!$m) not_found();
    $reason = trim((string)($_POST['reason'] ?? ''));
    reject_manager((int)$id, $reason);
    notify_manager_rejected($m, $reason);
    flash_set('ok', 'Compte de ' . $m['email'] . ' refusé.');
    redirect('/admin/managers');
}

function route_admin_delete_manager(string $id): void {
    require_admin();
    $m = find_manager_by_id((int)$id);
    if (!$m) not_found();
    delete_manager((int)$id);
    flash_set('ok', 'Compte de ' . $m['email'] . ' supprimé.');
    redirect('/admin/managers');
}

function notify_manager_validated(array $m): void {
    $app_url = rtrim($GLOBALS['CONFIG']['app_url'], '/');
    $name = $m['name'] !== '' ? $m['name'] : $m['email'];
    $subject = '[mmidate] Votre compte manager a été validé';
    $body  = "Bonjour $name,\n\n";
    $body .= "Votre compte manager mmidate vient d'être validé. Vous pouvez maintenant vous connecter :\n\n";
    $body .= "$app_url/login\n\n";
    $body .= "Bonne organisation !\n";
    try { send_mail($m['email'], $subject, $body); }
    catch (Throwable $e) { mail_log($m['email'], '[validation notif failed] ' . $e->getMessage(), $body); }
}

function notify_manager_rejected(array $m, string $reason): void {
    $name = $m['name'] !== '' ? $m['name'] : $m['email'];
    $subject = '[mmidate] Demande de compte manager refusée';
    $body  = "Bonjour $name,\n\n";
    $body .= "Votre demande de compte manager mmidate a été refusée.\n";
    if ($reason !== '') $body .= "\nMotif : $reason\n";
    $body .= "\nSi vous pensez qu'il s'agit d'une erreur, contactez l'administrateur·rice.\n";
    try { send_mail($m['email'], $subject, $body); }
    catch (Throwable $e) { mail_log($m['email'], '[rejection notif failed] ' . $e->getMessage(), $body); }
}
