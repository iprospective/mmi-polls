<?php
// Controller : accueil public.
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

function route_home(): void {
    if (is_admin()) redirect('/admin');
    render('home', ['page_title' => 'mmidate']);
}
