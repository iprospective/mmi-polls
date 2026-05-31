<?php
// Service Participants : liste, total créneaux d'un sondage.
require_once __DIR__ . '/../lib/db.php';

/**
 * Liste des participants d'un sondage.
 * @param bool $include_hidden  true (défaut) → toutes les personnes,
 *   false → exclut celles marquées hidden_in_public = 1 (pour la vue
 *   publique). Les vues admin/manager passent true.
 */
function poll_participants(int $poll_id, bool $include_hidden = true): array {
    $sql = "SELECT * FROM participants WHERE poll_id = ?";
    if (!$include_hidden) $sql .= " AND hidden_in_public = 0";
    $sql .= " ORDER BY created_at, id";
    $stmt = db()->prepare($sql);
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
