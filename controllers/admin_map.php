<?php
// Controller : carte de toutes les positions (admin + manager du sondage).
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../services/polls.php';
require_once __DIR__ . '/../services/participants.php';

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
    foreach (poll_participants((int)$poll['id']) as $p) {
        if ($p['latitude'] === null || $p['longitude'] === null) continue;
        $name = $p['name'] !== '' ? $p['name'] : explode('@', $p['email'])[0];
        $markers[] = [
            'lat'   => (float)$p['latitude'],
            'lng'   => (float)$p['longitude'],
            'kind'  => 'participant',
            'label' => $name,
            'desc'  => $p['geocoded_address'] ?: $p['address'],
        ];
    }

    render('admin/map', [
        'page_title' => 'Carte — ' . $poll['title'],
        'poll'       => $poll,
        'markers'    => $markers,
        'include_map' => true,
    ]);
}
