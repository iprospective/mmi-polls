<?php
// Controller : inscription, connexion (mdp + magic-link), déconnexion manager.
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/mailer.php';
require_once __DIR__ . '/../services/managers.php';

function route_register_form(): void {
    if (is_manager()) redirect('/manager');
    if (is_admin())   redirect('/admin');
    render('auth/register', ['page_title' => 'Créer un compte manager']);
}

function route_register(): void {
    $name  = trim((string)($_POST['name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $pwd   = (string)($_POST['password'] ?? '');
    $pwd2  = (string)($_POST['password_confirm'] ?? '');

    if ($name === '')                                  { flash_set('err', 'Nom obligatoire.'); redirect('/register'); }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))    { flash_set('err', 'Email invalide.'); redirect('/register'); }
    if (strlen($pwd) < 8)                              { flash_set('err', 'Mot de passe : 8 caractères minimum.'); redirect('/register'); }
    if ($pwd !== $pwd2)                                { flash_set('err', 'Les mots de passe ne correspondent pas.'); redirect('/register'); }
    if (find_manager_by_email($email))                 { flash_set('err', 'Un compte avec cet email existe déjà.'); redirect('/register'); }

    $mid = create_pending_manager($email, $name, $pwd);

    // Notif à l'admin global, si configuré.
    $admin_email = trim((string)($GLOBALS['CONFIG']['admin']['email'] ?? ''));
    if ($admin_email !== '' && filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
        $app_url = rtrim($GLOBALS['CONFIG']['app_url'], '/');
        $subject = '[mmidate] Nouvelle inscription manager : ' . $name;
        $body  = "Une nouvelle personne souhaite obtenir un compte manager :\n\n";
        $body .= "  Nom   : $name\n";
        $body .= "  Email : $email\n\n";
        $body .= "Valider / refuser depuis l'admin : $app_url/admin/managers\n";
        try { send_mail($admin_email, $subject, $body); }
        catch (Throwable $e) { mail_log($admin_email, '[admin notif failed] ' . $e->getMessage(), $body); }
    }

    flash_set('ok', 'Compte créé. Il doit être validé par l\'administrateur·rice avant utilisation. Vous recevrez un email de confirmation.');
    redirect('/login');
}

function route_login_form(): void {
    if (is_manager()) redirect('/manager');
    render('auth/login', ['page_title' => 'Connexion manager', 'sent' => false]);
}

function route_login(): void {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $pwd   = (string)($_POST['password'] ?? '');
    $m = find_manager_by_email($email);
    if (!$m || !password_verify($pwd, $m['password_hash'])) {
        flash_set('err', 'Identifiants incorrects.');
        redirect('/login');
    }
    if ($m['status'] === 'pending') {
        flash_set('err', 'Votre compte est en attente de validation par l\'administrateur·rice.');
        redirect('/login');
    }
    if ($m['status'] === 'rejected') {
        $why = $m['rejection_reason'] !== '' ? ' Motif : ' . $m['rejection_reason'] : '';
        flash_set('err', 'Votre compte a été refusé.' . $why);
        redirect('/login');
    }
    manager_login((int)$m['id'], $m['email'], $m['name']);
    flash_set('ok', 'Connecté·e en tant que ' . $m['email'] . '.');
    redirect('/manager');
}

function route_login_magic(): void {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_set('err', 'Email invalide.');
        redirect('/login');
    }
    $m = find_manager_by_email($email);
    // Anti-énumération : on rend toujours la même page, qu'il y ait un compte ou pas.
    if ($m && $m['status'] === 'active') {
        $token = issue_manager_magic_link((int)$m['id']);
        $link  = rtrim($GLOBALS['CONFIG']['app_url'], '/') . '/auth?token=' . urlencode($token);
        $subject = 'Lien de connexion manager';
        $body  = "Bonjour " . ($m['name'] !== '' ? $m['name'] : $m['email']) . ",\n\n";
        $body .= "Voici votre lien de connexion à mmidate :\n\n$link\n\n";
        $body .= "Ce lien est valable " . (int)($GLOBALS['CONFIG']['magic_link_ttl'] / 60) . " minutes.\n";
        try { send_mail($m['email'], $subject, $body); }
        catch (Throwable $e) { mail_log($m['email'], '[magic-link failed] ' . $e->getMessage(), $body); }
    }
    render('auth/login', ['page_title' => 'Lien envoyé', 'sent' => true, 'sent_to' => $email]);
}

function route_consume_magic(): void {
    $token = (string)($_GET['token'] ?? '');
    $mid   = consume_manager_magic_link($token);
    if ($mid === null) {
        flash_set('err', 'Lien invalide ou expiré.');
        redirect('/login');
    }
    $m = find_manager_by_id($mid);
    if (!$m || $m['status'] !== 'active') {
        flash_set('err', 'Compte indisponible.');
        redirect('/login');
    }
    manager_login((int)$m['id'], $m['email'], $m['name']);
    flash_set('ok', 'Connecté·e.');
    redirect('/manager');
}

function route_logout(): void {
    manager_logout();
    redirect('/');
}
