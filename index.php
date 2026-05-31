<?php
declare(strict_types=1);

// Front controller : bootstrap + dispatch.
//   php -S 127.0.0.1:8000 index.php
//   Apache : voir .htaccess (réécriture vers index.php)

// --- Static assets sous PHP cli-server ---------------------------------------
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($path !== '/'
        && strpos($path, '..') === false
        && strpos($path, '/public/') === 0
        && file_exists(__DIR__ . $path)) {
        return false; // let built-in server serve the asset
    }
}

// --- Bootstrap ---------------------------------------------------------------
$GLOBALS['CONFIG'] = require __DIR__ . '/config.php';

require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/helpers.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/mailer.php';
require __DIR__ . '/lib/html_sanitize.php';

session_name('mmidate');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();

// --- Dispatch ----------------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
if ($method === 'POST') csrf_check();

$routes = require __DIR__ . '/routes.php';

foreach ($routes as [$m, $pattern, $controller, $fn]) {
    if ($m !== $method) continue;
    if (preg_match($pattern, $path, $matches)) {
        require_once __DIR__ . '/controllers/' . $controller . '.php';
        array_shift($matches);
        $fn(...$matches);
        exit;
    }
}
not_found();
