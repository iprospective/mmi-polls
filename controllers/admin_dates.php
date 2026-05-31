<?php
// Controller : gestion des dates et créneaux d'un sondage.
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../services/polls.php';

function route_admin_dates(string $uuid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    $dates = poll_structure((int)$poll['id']);
    render('admin/dates', [
        'page_title' => 'Dates & créneaux — ' . $poll['title'],
        'poll' => $poll,
        'dates' => $dates,
    ]);
}

function route_admin_add_date(string $uuid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    $day = trim((string)($_POST['day'] ?? ''));
    $choices_raw = trim((string)($_POST['choices'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
        flash_set('err', 'Date invalide (format AAAA-MM-JJ attendu).');
        redirect('/admin/polls/' . $uuid . '/dates');
    }
    $labels = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $choices_raw)), fn($s) => $s !== ''));
    if (!$labels) {
        flash_set('err', 'Au moins un créneau requis (un par ligne).');
        redirect('/admin/polls/' . $uuid . '/dates');
    }
    $pdo = db();
    $pdo->beginTransaction();
    $ins = $pdo->prepare("INSERT INTO poll_dates (poll_id, day, sort_order) VALUES (?, ?, 0)");
    $ins->execute([$poll['id'], $day]);
    $date_id = (int)$pdo->lastInsertId();
    $insc = $pdo->prepare("INSERT INTO poll_choices (date_id, label, sort_order) VALUES (?, ?, ?)");
    foreach ($labels as $i => $lbl) {
        $insc->execute([$date_id, $lbl, $i]);
    }
    $pdo->commit();
    flash_set('ok', 'Date ajoutée.');
    redirect('/admin/polls/' . $uuid . '/dates');
}

function route_admin_add_dates_bulk(string $uuid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);

    $from_raw = trim((string)($_POST['date_from'] ?? ''));
    $to_raw   = trim((string)($_POST['date_to']   ?? ''));
    $choices_raw = trim((string)($_POST['choices'] ?? ''));
    // Jours de la semaine cochés : ISO 1=lundi … 7=dimanche
    $weekdays_in = $_POST['weekdays'] ?? [];
    if (!is_array($weekdays_in)) $weekdays_in = [];
    $weekdays = array_filter(array_map('intval', $weekdays_in), fn($d) => $d >= 1 && $d <= 7);

    $back = '/admin/polls/' . $uuid . '/dates';

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_raw) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_raw)) {
        flash_set('err', 'Plage invalide : dates AAAA-MM-JJ requises.');
        redirect($back);
    }
    if ($from_raw > $to_raw) {
        flash_set('err', 'La date de début doit être avant la date de fin.');
        redirect($back);
    }
    $labels = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $choices_raw)), fn($s) => $s !== ''));
    if (!$labels) {
        flash_set('err', 'Au moins un créneau requis (un par ligne).');
        redirect($back);
    }
    if (!$weekdays) {
        flash_set('err', 'Sélectionnez au moins un jour de la semaine.');
        redirect($back);
    }

    // Garde-fou : pas plus de 366 jours en une fois.
    $start = new DateTimeImmutable($from_raw);
    $end   = new DateTimeImmutable($to_raw);
    $span_days = $start->diff($end)->days;
    if ($span_days > 366) {
        flash_set('err', 'Plage trop large (max 366 jours en une opération).');
        redirect($back);
    }

    $pdo = db();
    $check  = $pdo->prepare("SELECT id FROM poll_dates WHERE poll_id = ? AND day = ?");
    $ins_d  = $pdo->prepare("INSERT INTO poll_dates (poll_id, day, sort_order) VALUES (?, ?, 0)");
    $ins_c  = $pdo->prepare("INSERT INTO poll_choices (date_id, label, sort_order) VALUES (?, ?, ?)");

    $pdo->beginTransaction();
    $created  = 0;
    $skipped  = 0;
    $iso = $start;
    while ($iso <= $end) {
        $weekday = (int)$iso->format('N'); // 1..7
        if (in_array($weekday, $weekdays, true)) {
            $day_str = $iso->format('Y-m-d');
            $check->execute([$poll['id'], $day_str]);
            if ($check->fetch()) {
                $skipped++;
            } else {
                $ins_d->execute([$poll['id'], $day_str]);
                $date_id = (int)$pdo->lastInsertId();
                foreach ($labels as $i => $lbl) {
                    $ins_c->execute([$date_id, $lbl, $i]);
                }
                $created++;
            }
        }
        $iso = $iso->modify('+1 day');
    }
    $pdo->commit();

    $msg = "$created date" . ($created > 1 ? 's' : '') . " ajoutée" . ($created > 1 ? 's' : '');
    if ($skipped > 0) $msg .= ", $skipped déjà existante" . ($skipped > 1 ? 's' : '') . " ignorée" . ($skipped > 1 ? 's' : '');
    $msg .= '.';
    flash_set('ok', $msg);
    redirect($back);
}

function route_admin_delete_date(string $uuid, string $date_id): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    $stmt = db()->prepare("DELETE FROM poll_dates WHERE id = ? AND poll_id = ?");
    $stmt->execute([(int)$date_id, $poll['id']]);
    flash_set('ok', 'Date supprimée.');
    redirect('/admin/polls/' . $uuid . '/dates');
}

function route_admin_add_choice(string $uuid, string $date_id): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    $label = trim((string)($_POST['label'] ?? ''));
    if ($label === '') {
        flash_set('err', 'Libellé requis.');
        redirect('/admin/polls/' . $uuid . '/dates');
    }
    $pdo = db();
    $check = $pdo->prepare("SELECT id FROM poll_dates WHERE id = ? AND poll_id = ?");
    $check->execute([(int)$date_id, $poll['id']]);
    if (!$check->fetch()) not_found();
    $max = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 AS n FROM poll_choices WHERE date_id = ?");
    $max->execute([(int)$date_id]);
    $order = (int)$max->fetch()['n'];
    $ins = $pdo->prepare("INSERT INTO poll_choices (date_id, label, sort_order) VALUES (?, ?, ?)");
    $ins->execute([(int)$date_id, $label, $order]);
    flash_set('ok', 'Créneau ajouté.');
    redirect('/admin/polls/' . $uuid . '/dates');
}

function route_admin_delete_choice(string $uuid, string $choice_id): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    $stmt = db()->prepare("
        DELETE FROM poll_choices
        WHERE id = ?
          AND date_id IN (SELECT id FROM poll_dates WHERE poll_id = ?)
    ");
    $stmt->execute([(int)$choice_id, $poll['id']]);
    flash_set('ok', 'Créneau supprimé.');
    redirect('/admin/polls/' . $uuid . '/dates');
}
