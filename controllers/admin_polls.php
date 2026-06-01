<?php
// Controller : liste des sondages, création / vue / mise à jour / suppression.
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/html_sanitize.php';
require_once __DIR__ . '/../services/polls.php';
require_once __DIR__ . '/../services/participants.php';
require_once __DIR__ . '/../services/votes.php';
require_once __DIR__ . '/../services/assignments.php';
require_once __DIR__ . '/../services/poll_managers.php';
require_once __DIR__ . '/../services/geocoder.php';

function route_admin_list(): void {
    require_admin();
    $polls = db()->query("
        SELECT p.*, m.email AS manager_email, m.name AS manager_name
        FROM polls p
        LEFT JOIN managers m ON m.id = p.manager_id
        ORDER BY p.created_at DESC
    ")->fetchAll();
    render('admin/list', ['page_title' => 'Sondages', 'polls' => $polls, 'include_editor' => true]);
}

function route_admin_create_poll(): void {
    if (!is_admin() && !is_manager()) redirect('/login');
    $title = trim((string)($_POST['title'] ?? ''));
    $desc  = sanitize_html((string)($_POST['description'] ?? ''));
    $back  = is_admin() ? '/admin' : '/manager';
    if ($title === '') {
        flash_set('err', 'Titre obligatoire.');
        redirect($back);
    }
    $uuid = uuid_v4();
    $manager_id = is_admin() ? null : current_manager_id();
    $pdo = db();
    $stmt = $pdo->prepare("INSERT INTO polls (uuid, title, description, created_at, manager_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$uuid, $title, $desc, time(), $manager_id]);
    $poll_id = (int)$pdo->lastInsertId();
    if ($manager_id !== null) {
        add_poll_manager($poll_id, $manager_id);
    }
    redirect('/admin/polls/' . $uuid);
}

function route_admin_poll(string $uuid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    $dates = poll_structure((int)$poll['id']);
    $participants = poll_participants((int)$poll['id']);
    $votes = poll_votes_map((int)$poll['id']);
    $assigns = poll_assignments_map((int)$poll['id']);
    render('admin/poll', [
        'page_title' => $poll['title'],
        'poll' => $poll,
        'dates' => $dates,
        'participants' => $participants,
        'votes' => $votes,
        'assigns' => $assigns,
    ]);
}

function route_admin_update_poll(string $uuid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    $title = trim((string)($_POST['title'] ?? ''));
    $desc  = sanitize_html((string)($_POST['description'] ?? ''));
    $closed_at = trim((string)($_POST['closed_at'] ?? ''));
    if ($closed_at !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $closed_at)) {
        flash_set('err', 'Date de clôture invalide.');
        redirect('/admin/polls/' . $uuid . '/settings');
    }
    if ($title === '') {
        flash_set('err', 'Titre obligatoire.');
        redirect('/admin/polls/' . $uuid . '/settings');
    }

    // Géocode des points GPS uniquement si la feature est active ET que
    // les champs ont été soumis (sinon on préserve l'existant, sinon
    // désactiver puis re-sauver perdrait les adresses déjà saisies).
    $addr_enabled = isset($_POST['addresses_enabled']) ? 1 : 0;
    $start_addr = $poll['start_address'] ?? '';
    $end_addr   = $poll['end_address']   ?? '';
    $start_lat  = $poll['start_lat']     ?? null;
    $start_lng  = $poll['start_lng']     ?? null;
    $start_geocoded = (string)($poll['start_geocoded'] ?? '');
    $end_lat    = $poll['end_lat']       ?? null;
    $end_lng    = $poll['end_lng']       ?? null;
    $end_geocoded   = (string)($poll['end_geocoded']   ?? '');
    $geo_errors = [];
    if ($addr_enabled && isset($_POST['start_address'])) {
        $start_addr = trim((string)$_POST['start_address']);
        [$start_lat, $start_lng, $start_geocoded] = geo_resolve($start_addr, $poll, 'start');
        if ($start_addr !== '' && $start_lat === null) $geo_errors[] = 'départ';
    }
    if ($addr_enabled && isset($_POST['end_address'])) {
        $end_addr = trim((string)$_POST['end_address']);
        [$end_lat, $end_lng, $end_geocoded] = geo_resolve($end_addr, $poll, 'end');
        if ($end_addr !== '' && $end_lat === null) $geo_errors[] = 'arrivée';
    }

    $assigns_public = isset($_POST['assignments_public']) ? 1 : 0;

    $stmt = db()->prepare("
        UPDATE polls
        SET title = ?, description = ?, closed_at = ?,
            start_address = ?, start_lat = ?, start_lng = ?, start_geocoded = ?,
            end_address   = ?, end_lat   = ?, end_lng   = ?, end_geocoded   = ?,
            assignments_public = ?, addresses_enabled = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $title, $desc, $closed_at,
        $start_addr, $start_lat, $start_lng, $start_geocoded,
        $end_addr,   $end_lat,   $end_lng,   $end_geocoded,
        $assigns_public, $addr_enabled,
        $poll['id'],
    ]);
    if ($geo_errors) {
        flash_set('err', 'Géocodage en échec pour : ' . implode(', ', $geo_errors)
            . '. Voir data/geocode.log pour le détail. Bouton « Retenter » à côté du champ pour bypasser le cache.');
    }
    flash_set('ok', 'Sondage mis à jour.');
    redirect('/admin/polls/' . $uuid . '/settings');
}

/**
 * Force un nouveau géocodage en supprimant l'entrée de cache pour
 * l'adresse courante du sondage (start ou end), puis redirige vers
 * Settings. Le prochain hit régénérera lat/lng.
 */
function route_admin_retry_geocode(string $uuid, string $kind): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    if (!in_array($kind, ['start', 'end'], true)) not_found();
    $addr = (string)($poll[$kind . '_address'] ?? '');
    if ($addr === '') {
        flash_set('err', 'Pas d\'adresse à re-géocoder.');
        redirect('/admin/polls/' . $uuid . '/settings');
    }
    // Purge l'entrée de cache pour cette adresse précise
    $hash = hash('sha256', mb_strtolower(trim($addr)));
    db()->prepare("DELETE FROM geocode_cache WHERE query_hash = ?")->execute([$hash]);

    require_once __DIR__ . '/../services/geocoder.php';
    $geo = geocode($addr);
    if ($geo) {
        $stmt = db()->prepare("UPDATE polls SET {$kind}_lat = ?, {$kind}_lng = ?, {$kind}_geocoded = ? WHERE id = ?");
        $stmt->execute([$geo['lat'], $geo['lng'], $geo['display_name'], $poll['id']]);
        flash_set('ok', "Géocodage OK : " . $geo['display_name']);
    } else {
        flash_set('err', "Géocodage toujours en échec. Voir data/geocode.log pour la cause.");
    }
    redirect('/admin/polls/' . $uuid . '/settings');
}

/**
 * Résout (lat, lng, display_name) pour une adresse libre. Réutilise les
 * coords existantes du sondage si l'adresse n'a pas changé. Sinon reset
 * complet + nouvelle requête au géocodeur. Retourne [null,null,''] si
 * non géocodable ou vide.
 */
function geo_resolve(string $address, array $poll, string $kind): array {
    if ($address === '') return [null, null, ''];
    $old = (string)($poll[$kind . '_address'] ?? '');
    $cached_lat = $poll[$kind . '_lat'] ?? null;
    // Réutilise les coords stockées si l'adresse n'a pas changé ET qu'on
    // a déjà un résultat positif. Sinon (adresse modifiée OU lat manquante),
    // on retente le géocodage — utile quand une 1re tentative a échoué.
    if ($address === $old && $cached_lat !== null) {
        return [
            $cached_lat,
            $poll[$kind . '_lng'] ?? null,
            (string)($poll[$kind . '_geocoded'] ?? ''),
        ];
    }
    $geo = geocode($address);
    return $geo
        ? [$geo['lat'], $geo['lng'], $geo['display_name']]
        : [null, null, ''];
}

function route_admin_delete_poll(string $uuid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    $stmt = db()->prepare("DELETE FROM polls WHERE id = ?");
    $stmt->execute([$poll['id']]);
    flash_set('ok', 'Sondage supprimé.');
    redirect('/admin');
}
