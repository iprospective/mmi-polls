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
    // Pour récupérer la flag assignments_public, on relit la ligne polls.
    require_once __DIR__ . '/../services/polls.php';
    $poll = find_poll($row['poll_uuid']);
    if (empty($poll['assignments_public'])) {
        // Sondage en mode brouillon : on rend un calendrier vide
        // pour que les éventuels abonnements restent fonctionnels
        // mais cessent d'afficher les astreintes.
        $ics  = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//MMIrelay//draft//FR\r\n";
        $ics .= "X-WR-CALNAME:Astreintes (en attente de publication)\r\n";
        $ics .= "END:VCALENDAR\r\n";
    } else {
        $ics = build_ical_for_participant($row, $poll);
    }

    header('Content-Type: text/calendar; charset=UTF-8');
    header('Content-Disposition: inline; filename="MMIrelay-' . $poll['uuid'] . '.ics"');
    echo $ics;
}
