<?php
// Service Polls : lookup et structure d'un sondage.
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';

function find_poll(string $uuid): array {
    $stmt = db()->prepare("SELECT * FROM polls WHERE uuid = ?");
    $stmt->execute([$uuid]);
    $poll = $stmt->fetch();
    if (!$poll) not_found();
    return $poll;
}

/**
 * Renvoie la liste des dates du sondage avec, pour chacune, le tableau
 * de ses choices ($d['choices']).
 */
function poll_structure(int $poll_id): array {
    $pdo = db();
    $dates = $pdo->prepare("SELECT * FROM poll_dates WHERE poll_id = ? ORDER BY day, sort_order, id");
    $dates->execute([$poll_id]);
    $dates = $dates->fetchAll();
    if (!$dates) return [];

    $ids = array_column($dates, 'id');
    $place = implode(',', array_fill(0, count($ids), '?'));
    $choices = $pdo->prepare("SELECT * FROM poll_choices WHERE date_id IN ($place) ORDER BY sort_order, id");
    $choices->execute($ids);
    $bydate = [];
    foreach ($choices->fetchAll() as $c) {
        $bydate[$c['date_id']][] = $c;
    }
    foreach ($dates as &$d) {
        $d['choices'] = $bydate[$d['id']] ?? [];
    }
    return $dates;
}
