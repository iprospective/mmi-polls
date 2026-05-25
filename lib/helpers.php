<?php

function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void {
    $given = $_POST['_csrf'] ?? '';
    if (!is_string($given) || !hash_equals($_SESSION['csrf'] ?? '', $given)) {
        http_response_code(400);
        exit('Invalid CSRF token');
    }
}

function redirect(string $path): void {
    header('Location: ' . $path);
    exit;
}

function flash_set(string $type, string $msg): void {
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function flash_pop(): array {
    $out = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $out;
}

function render(string $tpl, array $vars = []): void {
    $vars['_flashes'] = flash_pop();
    extract($vars, EXTR_SKIP);
    $__tpl = $tpl;
    ob_start();
    require __DIR__ . '/../templates/' . $__tpl . '.php';
    $content = ob_get_clean();
    $title = $vars['page_title'] ?? 'mmidate';
    require __DIR__ . '/../templates/layout.php';
}

function not_found(): void {
    http_response_code(404);
    render('error', ['page_title' => 'Introuvable', 'message' => 'Page introuvable.']);
    exit;
}

function fmt_day(string $iso): string {
    static $jours = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
    static $mois  = ['', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    $t = strtotime($iso);
    if (!$t) return $iso;
    return $jours[(int)date('w', $t)] . ' ' . (int)date('j', $t) . ' ' . $mois[(int)date('n', $t)];
}
