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
    $title = $vars['page_title'] ?? 'MMIrelay';
    require __DIR__ . '/../templates/layout.php';
}

function not_found(): void {
    http_response_code(404);
    render('error', ['page_title' => 'Introuvable', 'message' => 'Page introuvable.']);
    exit;
}

function contact_methods(): array {
    return [
        'telegram' => 'Telegram',
        'signal'   => 'Signal',
        'whatsapp' => 'WhatsApp',
        'sms'      => 'SMS',
    ];
}
function contact_method_label(string $method): string {
    return contact_methods()[$method] ?? '';
}
function parse_contact_methods(?string $stored): array {
    if ($stored === null || $stored === '') return [];
    $arr = array_map('trim', explode(',', $stored));
    $valid = array_keys(contact_methods());
    return array_values(array_intersect($arr, $valid));
}
function contact_methods_pills(?string $stored): string {
    $methods = parse_contact_methods($stored);
    if (!$methods) return '';
    $out = '';
    foreach ($methods as $m) {
        $out .= '<span class="contact-pill cm-' . e($m) . '" title="' . e(contact_method_label($m)) . '">'
              . e(contact_method_label($m)) . '</span>';
    }
    return $out;
}
function sanitize_phone(string $raw): string {
    // Garde chiffres, +, espaces, tirets, points, parenthèses ; limite à 32 chars.
    $clean = preg_replace('/[^0-9+\-.\s()]/u', '', $raw) ?? '';
    return mb_substr(trim($clean), 0, 32);
}

/**
 * Append the asset_version param to a /public/* URL for cache-busting.
 * Usage : <link href="<?= asset_url('/public/style.css') ?>">
 * À chaque modif de CSS/JS, bumper CONFIG.asset_version.
 */
/**
 * Gestion d'adresses active pour un sondage ?
 * Combinaison AND du master switch global (CONFIG.addresses.enabled)
 * et du flag par-sondage (polls.addresses_enabled).
 */
function poll_addresses_enabled(array $poll): bool {
    $global = (bool)($GLOBALS['CONFIG']['addresses']['enabled'] ?? true);
    $local  = !empty($poll['addresses_enabled']);
    return $global && $local;
}

function addresses_globally_enabled(): bool {
    return (bool)($GLOBALS['CONFIG']['addresses']['enabled'] ?? true);
}

/**
 * Renvoie la map des horaires de créneaux pour un sondage, indexée
 * par libellé en minuscules. Fusion de :
 *   1. Défauts (Journée 7-20, Soirée 18-00, Nuit 22-09)
 *   2. Overrides éventuels stockés dans poll.slot_hours (JSON)
 *
 * Format renvoyé : ['journée' => ['start' => '07:00', 'end' => '20:00'], …]
 * Les chevauchements défaut sont volontaires : marge de transport pour
 * éviter qu'un retard ne déborde sur le créneau suivant.
 */
function poll_slot_hours_map(array $poll): array {
    $map = [
        'journée' => ['start' => '07:00', 'end' => '20:00'],
        'journee' => ['start' => '07:00', 'end' => '20:00'],
        'jour'    => ['start' => '07:00', 'end' => '20:00'],
        'soirée'  => ['start' => '18:00', 'end' => '00:00'],
        'soiree'  => ['start' => '18:00', 'end' => '00:00'],
        'soir'    => ['start' => '18:00', 'end' => '00:00'],
        'nuit'    => ['start' => '22:00', 'end' => '09:00'],
    ];
    $stored = (string)($poll['slot_hours'] ?? '');
    if ($stored !== '') {
        $parsed = json_decode($stored, true);
        if (is_array($parsed)) {
            foreach ($parsed as $label => $hours) {
                if (!is_array($hours)) continue;
                $start = (string)($hours['start'] ?? '');
                $end   = (string)($hours['end']   ?? '');
                if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) continue;
                $map[mb_strtolower($label)] = ['start' => $start, 'end' => $end];
            }
        }
    }
    return $map;
}

function poll_slot_hours_lookup(array $poll, string $label): ?array {
    $map = poll_slot_hours_map($poll);
    return $map[mb_strtolower($label)] ?? null;
}

/**
 * Distance en km entre deux points (lat, lng) — formule Haversine.
 */
function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $R * 2 * asin(sqrt($a));
}

/**
 * Sondage clos ? true si poll.closed_at est non-vide ET dans le passé.
 */
function poll_is_closed(array $poll): bool {
    $closed = (string)($poll['closed_at'] ?? '');
    if ($closed === '') return false;
    return $closed < date('Y-m-d');
}

function asset_url(string $path): string {
    $v = (int)($GLOBALS['CONFIG']['asset_version'] ?? 1);
    $sep = strpos($path, '?') === false ? '?' : '&';
    return $path . $sep . 'v=' . $v;
}

/**
 * ID du·de la participant·e « focus » du sondage (typiquement la maman
 * dont on transporte le bébé), 0 si pas configuré. Cette personne a un
 * marker dédié sur la carte et son trajet est toujours visible.
 */
function poll_focus_participant_id(array $poll): int {
    return (int)($poll['focus_participant_id'] ?? 0);
}

/**
 * URL absolue d'une icône custom pour le marker focus si l'asset existe
 * dans public/, sinon null (le JS retombe sur un emoji par défaut).
 * Permet à l'utilisateur de déposer son propre PNG sans toucher au code.
 */
function poll_focus_icon_url(): ?string {
    $path = __DIR__ . '/../public/focus-pin.png';
    return file_exists($path) ? asset_url('/public/focus-pin.png') : null;
}

function fmt_day(string $iso): string {
    static $jours = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
    static $mois  = ['', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    $t = strtotime($iso);
    if (!$t) return $iso;
    return $jours[(int)date('w', $t)] . ' ' . (int)date('j', $t) . ' ' . $mois[(int)date('n', $t)];
}
