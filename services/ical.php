<?php
// Service iCal : génère un flux .ics pour les astreintes d'un participant.
// Format RFC 5545, encodage CRLF, fold à 75 caractères.
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../services/assignments.php';

function get_or_create_ical_token(int $participant_id): string {
    $pdo  = db();
    $stmt = $pdo->prepare("SELECT ical_token FROM participants WHERE id = ?");
    $stmt->execute([$participant_id]);
    $row = $stmt->fetch();
    if (!$row) return '';
    if ($row['ical_token'] !== '') return $row['ical_token'];
    $token = bin2hex(random_bytes(20));
    $upd = $pdo->prepare("UPDATE participants SET ical_token = ? WHERE id = ?");
    $upd->execute([$token, $participant_id]);
    return $token;
}

function find_participant_by_ical_token(string $token): ?array {
    if ($token === '') return null;
    $stmt = db()->prepare("
        SELECT p.*, po.uuid AS poll_uuid, po.title AS poll_title
        FROM participants p
        JOIN polls po ON po.id = p.poll_id
        WHERE p.ical_token = ?
    ");
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

/**
 * Génère le flux iCal pour les astreintes d'un·e participant·e.
 * Convention sur les durées (RFC 5545 DTSTART/DTEND) :
 *  - "Journée"  : 09:00 → 19:00
 *  - "Soirée"   : 19:00 → 23:00
 *  - "Nuit"     : 23:00 → 07:00 (le lendemain)
 *  - autre libellé : toute la journée (DTSTART;VALUE=DATE)
 */
function build_ical_for_participant(array $participant, array $poll): string {
    $assigns = assignments_for_participant((int)$poll['id'], (int)$participant['id']);
    $name = $participant['name'] !== '' ? $participant['name'] : explode('@', $participant['email'])[0];
    $cal_name = 'Astreintes — ' . $poll['title'];
    $host = parse_url($GLOBALS['CONFIG']['app_url'] ?? 'MMIrelay.local', PHP_URL_HOST) ?: 'MMIrelay.local';

    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//MMIrelay//' . $host . '//FR',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'X-WR-CALNAME:' . ical_escape($cal_name),
        'X-WR-TIMEZONE:Europe/Paris',
        'X-WR-CALDESC:Astreintes de ' . ical_escape($name) . ' pour le sondage « ' . ical_escape($poll['title']) . ' »',
    ];

    $now = gmdate('Ymd\THis\Z');
    foreach ($assigns as $a) {
        $day = $a['day'];
        $label = $a['label'];
        $role  = $a['role'] === 'primary' ? 'Principal·e' : 'Suppléant·e';
        [$dtstart, $dtend, $all_day] = ical_event_window($day, $label, $poll);
        $uid = 'astreinte-' . $participant['id'] . '-' . md5($day . $label . $a['role']) . '@' . $host;
        $summary = $role . ' — ' . $label . ' (' . $poll['title'] . ')';
        $lines[] = 'BEGIN:VEVENT';
        $lines[] = 'UID:' . $uid;
        $lines[] = 'DTSTAMP:' . $now;
        if ($all_day) {
            $lines[] = 'DTSTART;VALUE=DATE:' . $dtstart;
            $lines[] = 'DTEND;VALUE=DATE:'   . $dtend;
        } else {
            $lines[] = 'DTSTART;TZID=Europe/Paris:' . $dtstart;
            $lines[] = 'DTEND;TZID=Europe/Paris:'   . $dtend;
        }
        $lines[] = 'SUMMARY:'     . ical_escape($summary);
        $lines[] = 'DESCRIPTION:' . ical_escape("Sondage : {$poll['title']}\nCréneau : $label\nRôle : $role");
        $lines[] = 'END:VEVENT';
    }
    $lines[] = 'END:VCALENDAR';

    return implode("\r\n", array_map('ical_fold', $lines)) . "\r\n";
}

/**
 * Renvoie [dtstart, dtend, all_day] à partir des horaires configurés
 * pour le sondage (poll.slot_hours via poll_slot_hours_lookup).
 * Si le libellé n'a pas d'horaire défini, fallback all-day.
 * end <= start (en horaire pur) = passage au lendemain.
 */
function ical_event_window(string $day, string $label, array $poll): array {
    $start_date = new DateTimeImmutable($day);
    $next_date  = $start_date->modify('+1 day');

    $hours = poll_slot_hours_lookup($poll, $label);
    if ($hours === null) {
        return [$start_date->format('Ymd'), $next_date->format('Ymd'), true];
    }

    $start_hm = str_replace(':', '', $hours['start']) . '00';
    $end_hm   = str_replace(':', '', $hours['end'])   . '00';
    $dtstart  = $start_date->format('Ymd') . 'T' . $start_hm;
    // Si end <= start (heure pure), la fin est le lendemain
    $end_anchor = ($hours['end'] <= $hours['start']) ? $next_date : $start_date;
    $dtend = $end_anchor->format('Ymd') . 'T' . $end_hm;
    return [$dtstart, $dtend, false];
}

function ical_escape(string $s): string {
    return str_replace(
        ['\\',   "\r\n", "\n", ',',  ';'],
        ['\\\\', '\\n',  '\\n', '\,', '\;'],
        $s
    );
}

/**
 * RFC 5545 § 3.1 : ligne pliée à 75 octets, suivante préfixée d'un espace.
 */
function ical_fold(string $line): string {
    if (strlen($line) <= 75) return $line;
    $out = '';
    while (strlen($line) > 75) {
        $out .= substr($line, 0, 75) . "\r\n ";
        $line = substr($line, 75);
    }
    return $out . $line;
}
