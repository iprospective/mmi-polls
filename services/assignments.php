<?php
// Service Assignments : lecture des astreintes posées (par sondage, par participant).
require_once __DIR__ . '/../lib/db.php';

function poll_assignments_map(int $poll_id): array {
    $stmt = db()->prepare("
        SELECT a.choice_id, a.role, a.participant_id
        FROM assignments a
        JOIN poll_choices c ON c.id = a.choice_id
        JOIN poll_dates   d ON d.id = c.date_id
        WHERE d.poll_id = ?
    ");
    $stmt->execute([$poll_id]);
    $out = [];
    foreach ($stmt as $r) {
        $out[(int)$r['choice_id']][$r['role']] = (int)$r['participant_id'];
    }
    return $out;
}

function assignments_for_participant(int $poll_id, int $participant_id): array {
    $stmt = db()->prepare("
        SELECT d.day, c.label, c.id AS choice_id, a.role
        FROM assignments a
        JOIN poll_choices c ON c.id = a.choice_id
        JOIN poll_dates   d ON d.id = c.date_id
        WHERE d.poll_id = ? AND a.participant_id = ?
        ORDER BY d.day, c.sort_order, c.id
    ");
    $stmt->execute([$poll_id, $participant_id]);
    return $stmt->fetchAll();
}

/**
 * Bump participants.assignments_updated_at pour les IDs fournis. Sert à
 * marquer qu'une re-notification est nécessaire (les astreintes ont changé
 * depuis le dernier email).
 */
function mark_participants_assignments_stale(array $participant_ids): void {
    $pids = array_values(array_unique(array_map('intval', $participant_ids)));
    $pids = array_values(array_filter($pids, fn($p) => $p > 0));
    if (!$pids) return;
    $placeholders = implode(',', array_fill(0, count($pids), '?'));
    $stmt = db()->prepare(
        "UPDATE participants SET assignments_updated_at = ? WHERE id IN ($placeholders)"
    );
    $stmt->execute(array_merge([time()], $pids));
}

/**
 * Retourne tous les participant_ids ayant au moins une assignation sur ce sondage.
 * Utilisé avant un clear pour savoir qui bumper.
 */
function poll_assigned_participant_ids(int $poll_id): array {
    $stmt = db()->prepare("
        SELECT DISTINCT a.participant_id
        FROM assignments a
        JOIN poll_choices c ON c.id = a.choice_id
        JOIN poll_dates   d ON d.id = c.date_id
        WHERE d.poll_id = ?
    ");
    $stmt->execute([$poll_id]);
    return array_map('intval', array_column($stmt->fetchAll(), 'participant_id'));
}
