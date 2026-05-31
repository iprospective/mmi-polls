<?php
// Controller : vue calendrier mensuelle des astreintes d'un sondage.
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../services/polls.php';
require_once __DIR__ . '/../services/assignments.php';

function route_admin_calendar(string $uuid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    $pdo = db();

    // Mois affiché : ?month=YYYY-MM (défaut = aujourd'hui)
    $month = (string)($_GET['month'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }
    [$year, $mon] = array_map('intval', explode('-', $month));
    $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $mon));
    $last  = $first->modify('last day of this month');

    // Nav : mois précédent / suivant en bornant à la plage des dates du sondage.
    $prev_month = $first->modify('-1 month')->format('Y-m');
    $next_month = $first->modify('+1 month')->format('Y-m');

    // Toutes les assignations du sondage avec contexte participant + créneau.
    $stmt = $pdo->prepare("
        SELECT d.day, c.label, c.sort_order AS c_order, a.role,
               p.id AS pid, p.name, p.email
        FROM assignments a
        JOIN poll_choices c ON c.id = a.choice_id
        JOIN poll_dates   d ON d.id = c.date_id
        JOIN participants p ON p.id = a.participant_id
        WHERE d.poll_id = ?
        ORDER BY d.day, c.sort_order, c.id
    ");
    $stmt->execute([$poll['id']]);
    $rows = $stmt->fetchAll();

    // Indexer par jour : [day][label] = ['primary' => name, 'backup' => name]
    $by_day = [];
    foreach ($rows as $r) {
        $name = $r['name'] !== '' ? $r['name'] : explode('@', $r['email'])[0];
        $by_day[$r['day']][$r['label']][$r['role']] = $name;
    }

    // Plage du sondage pour limiter la nav et marquer les jours hors plage.
    $span = $pdo->prepare("SELECT MIN(day) AS min_d, MAX(day) AS max_d FROM poll_dates WHERE poll_id = ?");
    $span->execute([$poll['id']]);
    $span_row = $span->fetch();
    $poll_min = $span_row['min_d'] ?? '';
    $poll_max = $span_row['max_d'] ?? '';

    render('admin/calendar', [
        'page_title' => 'Calendrier — ' . $poll['title'],
        'poll' => $poll,
        'month_str' => $month,
        'first' => $first,
        'last' => $last,
        'prev_month' => $prev_month,
        'next_month' => $next_month,
        'by_day' => $by_day,
        'poll_min' => $poll_min,
        'poll_max' => $poll_max,
    ]);
}
