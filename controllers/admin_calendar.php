<?php
// Controller : vue calendrier mensuelle des astreintes d'un sondage.
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../services/polls.php';
require_once __DIR__ . '/../services/participants.php';
require_once __DIR__ . '/../services/assignments.php';

function route_admin_calendar(string $uuid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    $pdo = db();

    // Mois affiché
    $month = (string)($_GET['month'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }
    [$year, $mon] = array_map('intval', explode('-', $month));
    $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $mon));
    $prev_month = $first->modify('-1 month')->format('Y-m');
    $next_month = $first->modify('+1 month')->format('Y-m');

    // Filtre participant éventuel
    $selected_pid = (int)($_GET['participant'] ?? 0);
    $where_p = $selected_pid > 0 ? ' AND a.participant_id = ?' : '';
    $params  = [$poll['id']];
    if ($selected_pid > 0) $params[] = $selected_pid;

    $stmt = $pdo->prepare("
        SELECT d.day, c.label, c.sort_order AS c_order, a.role,
               p.id AS pid, p.name, p.email
        FROM assignments a
        JOIN poll_choices c ON c.id = a.choice_id
        JOIN poll_dates   d ON d.id = c.date_id
        JOIN participants p ON p.id = a.participant_id
        WHERE d.poll_id = ? $where_p
        ORDER BY d.day, c.sort_order, c.id
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $by_day = [];
    foreach ($rows as $r) {
        $name = $r['name'] !== '' ? $r['name'] : explode('@', $r['email'])[0];
        $by_day[$r['day']][$r['label']][$r['role']] = $name;
    }

    $span = $pdo->prepare("SELECT MIN(day) AS min_d, MAX(day) AS max_d FROM poll_dates WHERE poll_id = ?");
    $span->execute([$poll['id']]);
    $span_row = $span->fetch();
    $poll_min = $span_row['min_d'] ?? '';
    $poll_max = $span_row['max_d'] ?? '';

    $participants = poll_participants((int)$poll['id']);
    $selected_participant = null;
    if ($selected_pid > 0) {
        foreach ($participants as $p) {
            if ((int)$p['id'] === $selected_pid) { $selected_participant = $p; break; }
        }
    }

    render('admin/calendar', [
        'page_title'           => 'Calendrier — ' . $poll['title'],
        'poll'                 => $poll,
        'month_str'            => $month,
        'first'                => $first,
        'prev_month'           => $prev_month,
        'next_month'           => $next_month,
        'by_day'               => $by_day,
        'poll_min'             => $poll_min,
        'poll_max'             => $poll_max,
        'participants'         => $participants,
        'selected_pid'         => $selected_pid,
        'selected_participant' => $selected_participant,
    ]);
}
