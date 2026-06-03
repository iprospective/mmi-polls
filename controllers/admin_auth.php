<?php
// Controller : connexion/déconnexion admin.
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

function route_admin_login_form(): void {
    if (is_admin()) redirect('/admin');
    // Page de connexion unifiée (admin + manager + note participants).
    // Garde la route /admin/login pour les vieux bookmarks et liens.
    render('auth/login', ['page_title' => 'Connexion', 'sent' => false]);
}

function route_admin_login(): void {
    $u = (string)($_POST['username'] ?? '');
    $p = (string)($_POST['password'] ?? '');
    if (admin_login($u, $p)) {
        flash_set('ok', 'Connecté.');
        redirect('/admin');
    }
    flash_set('err', 'Identifiants invalides.');
    redirect('/admin/login');
}

function route_admin_logout(): void {
    admin_logout();
    redirect('/');
}
