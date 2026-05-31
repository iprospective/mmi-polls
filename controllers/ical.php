<?php
// Controller : flux iCal des astreintes (accessible par token, sans session).
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../services/ical.php';

function route_ical_feed(string $token): void {
    $row = find_participant_by_ical_token($token);
    if (!$row) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Token iCal introuvable ou révoqué.\n";
        exit;
    }
    // Reconstituer un array $poll au format attendu par build_ical_for_participant
    $poll = [
        'id'    => (int)$row['poll_id'],
        'uuid'  => $row['poll_uuid'],
        'title' => $row['poll_title'],
    ];
    $ics = build_ical_for_participant($row, $poll);

    header('Content-Type: text/calendar; charset=UTF-8');
    header('Content-Disposition: inline; filename="mmidate-' . $poll['uuid'] . '.ics"');
    echo $ics;
}
