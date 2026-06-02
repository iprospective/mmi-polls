<?php
// Controller : carte de toutes les positions (admin + manager du sondage).
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../services/polls.php';
require_once __DIR__ . '/../services/participants.php';
require_once __DIR__ . '/../services/routing.php';

function route_admin_map(string $uuid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);

    $markers = [];

    if ($poll['start_lat'] !== null && $poll['start_lng'] !== null) {
        $markers[] = [
            'lat'   => (float)$poll['start_lat'],
            'lng'   => (float)$poll['start_lng'],
            'kind'  => 'start',
            'label' => 'Départ',
            'desc'  => $poll['start_geocoded'] ?: $poll['start_address'],
        ];
    }
    if ($poll['end_lat'] !== null && $poll['end_lng'] !== null) {
        $markers[] = [
            'lat'   => (float)$poll['end_lat'],
            'lng'   => (float)$poll['end_lng'],
            'kind'  => 'end',
            'label' => 'Arrivée',
            'desc'  => $poll['end_geocoded'] ?: $poll['end_address'],
        ];
    }

    // Trajets : seulement si le sondage a départ ET arrivée géocodés.
    $can_route = $poll['start_lat'] !== null && $poll['start_lng'] !== null
              && $poll['end_lat']   !== null && $poll['end_lng']   !== null;

    $trips = []; // payload pour la carte : [{pid, name, color, total_m, legs:[{name,distance_m,duration_s,geometry}]}, …]
    $palette = [
        '#2563eb', '#7c3aed', '#db2777', '#ea580c', '#ca8a04',
        '#0d9488', '#65a30d', '#9333ea', '#0891b2', '#dc2626',
    ];
    $i = 0;
    foreach (poll_participants((int)$poll['id']) as $p) {
        if ($p['latitude'] === null || $p['longitude'] === null) continue;
        $name = $p['name'] !== '' ? $p['name'] : explode('@', $p['email'])[0];
        $color = $palette[$i % count($palette)];
        $markers[] = [
            'lat'   => (float)$p['latitude'],
            'lng'   => (float)$p['longitude'],
            'kind'  => 'participant',
            'pid'   => (int)$p['id'],
            'color' => $color,
            'label' => $name,
            'desc'  => $p['geocoded_address'] ?: $p['address'],
        ];
        if ($can_route) {
            $t = participant_trip($poll, $p);
            if ($t['complete']) {
                $trips[] = [
                    'pid'      => (int)$p['id'],
                    'name'     => $name,
                    'color'    => $color,
                    'total_m'  => $t['total_m'],
                    'total_s'  => $t['total_s'],
                    'legs'     => $t['legs'],
                ];
            }
        }
        $i++;
    }

    render('admin/map', [
        'page_title' => 'Carte — ' . $poll['title'],
        'poll'       => $poll,
        'markers'    => $markers,
        'trips'      => $trips,
        'can_route'  => $can_route,
        'include_map' => true,
    ]);
}

/**
 * Bouton « Recalculer les trajets » : purge le cache route_cache pour
 * toutes les paires (chez-moi, départ/arrivée) du sondage. Au prochain
 * load de la carte, route_between() re-tape OSRM. Utile si OSRM était
 * down au 1er calcul (fallback Haversine appliqué) ou si on a changé
 * l'adresse de départ/arrivée du sondage.
 */
function route_admin_recompute_routes(string $uuid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    if ($poll['start_lat'] === null || $poll['end_lat'] === null) {
        flash_set('err', 'Renseignez l\'adresse de départ ET d\'arrivée pour calculer des trajets.');
        redirect('/admin/polls/' . $uuid . '/map');
    }
    $count = 0;
    // Leg poll-wide : start↔end (1 paire)
    route_cache_invalidate((float)$poll['start_lat'], (float)$poll['start_lng'],
                           (float)$poll['end_lat'],   (float)$poll['end_lng']);
    $count++;
    foreach (poll_participants((int)$poll['id']) as $p) {
        if ($p['latitude'] === null || $p['longitude'] === null) continue;
        // home → start
        route_cache_invalidate((float)$p['latitude'], (float)$p['longitude'],
                               (float)$poll['start_lat'], (float)$poll['start_lng']);
        // end → home
        route_cache_invalidate((float)$poll['end_lat'], (float)$poll['end_lng'],
                               (float)$p['latitude'], (float)$p['longitude']);
        $count += 2;
    }
    flash_set('ok', "$count trajet(s) invalidé(s). Ils seront recalculés au prochain affichage de la carte.");
    redirect('/admin/polls/' . $uuid . '/map');
}
