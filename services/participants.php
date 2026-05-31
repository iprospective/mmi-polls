<?php
// Service Participants : liste, total créneaux d'un sondage.
require_once __DIR__ . '/../lib/db.php';

function poll_participants(int $poll_id): array {
    $stmt = db()->prepare("SELECT * FROM participants WHERE poll_id = ? ORDER BY created_at, id");
    $stmt->execute([$poll_id]);
    return $stmt->fetchAll();
}

function poll_total_choices(int $poll_id): int {
    $stmt = db()->prepare("
        SELECT COUNT(*) FROM poll_choices c
        JOIN poll_dates d ON d.id = c.date_id
        WHERE d.poll_id = ?
    ");
    $stmt->execute([$poll_id]);
    return (int)$stmt->fetchColumn();
}
