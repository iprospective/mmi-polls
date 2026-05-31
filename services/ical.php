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
    $host = parse_url($GLOBALS['CONFIG']['app_url'] ?? 'mmidate.local', PHP_URL_HOST) ?: 'mmidate.local';

    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//mmidate//' . $host . '//FR',
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
        [$dtstart, $dtend, $all_day] = ical_event_window($day, $label);
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
 * Renvoie [dtstart, dtend, all_day]. dtstart/dtend en format YYYYMMDD
 * (all-day) ou YYYYMMDDTHHMMSS (avec heure locale TZID Europe/Paris).
 */
function ical_event_window(string $day, string $label): array {
    $key = mb_strtolower($label);
    $start = new DateTimeImmutable($day);
    $next  = $start->modify('+1 day');
    if ($key === 'journée' || $key === 'journee' || $key === 'jour') {
        return [
            $start->format('Ymd\T') . '090000',
            $start->format('Ymd\T') . '190000',
            false,
        ];
    }
    if ($key === 'soirée' || $key === 'soiree' || $key === 'soir') {
        return [
            $start->format('Ymd\T') . '190000',
            $start->format('Ymd\T') . '230000',
            false,
        ];
    }
    if ($key === 'nuit') {
        return [
            $start->format('Ymd\T') . '230000',
            $next->format('Ymd\T')  . '070000',
            false,
        ];
    }
    // Libellé inconnu → all-day (DTEND exclusif → +1 jour)
    return [$start->format('Ymd'), $next->format('Ymd'), true];
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
