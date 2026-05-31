<?php
// Service Poll Managers : association M2M sondages ↔ managers.
require_once __DIR__ . '/../lib/db.php';

function poll_has_manager(int $poll_id, int $manager_id): bool {
    $stmt = db()->prepare("SELECT 1 FROM poll_managers WHERE poll_id = ? AND manager_id = ?");
    $stmt->execute([$poll_id, $manager_id]);
    return (bool)$stmt->fetchColumn();
}

function list_poll_managers(int $poll_id): array {
    $stmt = db()->prepare("
        SELECT m.id, m.email, m.name, m.status,
               pm.added_at, pm.added_by_admin, pm.invited_by,
               inv.name AS inviter_name, inv.email AS inviter_email
        FROM poll_managers pm
        JOIN managers m ON m.id = pm.manager_id
        LEFT JOIN managers inv ON inv.id = pm.invited_by
        WHERE pm.poll_id = ?
        ORDER BY pm.added_at
    ");
    $stmt->execute([$poll_id]);
    return $stmt->fetchAll();
}

function list_polls_managed_by(int $manager_id): array {
    $stmt = db()->prepare("
        SELECT p.*
        FROM polls p
        JOIN poll_managers pm ON pm.poll_id = p.id
        WHERE pm.manager_id = ?
        ORDER BY p.created_at DESC
    ");
    $stmt->execute([$manager_id]);
    return $stmt->fetchAll();
}

/**
 * Ajoute un manager à un sondage. Renvoie true si une nouvelle ligne
 * a été créée, false si déjà présente.
 *
 * @param int      $poll_id
 * @param int      $manager_id        manager qu'on ajoute
 * @param bool     $by_admin          true si l'action vient de l'admin global
 * @param int|null $invited_by_id     id du manager qui invite (null si admin)
 */
function add_poll_manager(int $poll_id, int $manager_id, bool $by_admin = false, ?int $invited_by_id = null): bool {
    if (poll_has_manager($poll_id, $manager_id)) return false;
    $stmt = db()->prepare("
        INSERT INTO poll_managers (poll_id, manager_id, added_at, added_by_admin, invited_by)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$poll_id, $manager_id, time(), $by_admin ? 1 : 0, $invited_by_id]);
    return true;
}

function remove_poll_manager(int $poll_id, int $manager_id): void {
    $stmt = db()->prepare("DELETE FROM poll_managers WHERE poll_id = ? AND manager_id = ?");
    $stmt->execute([$poll_id, $manager_id]);
}
