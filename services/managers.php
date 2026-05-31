<?php
// Service Managers : CRUD + lookup + magic-link auth.
require_once __DIR__ . '/../lib/db.php';

function find_manager_by_email(string $email): ?array {
    $stmt = db()->prepare("SELECT * FROM managers WHERE email = ?");
    $stmt->execute([strtolower($email)]);
    return $stmt->fetch() ?: null;
}

function find_manager_by_id(int $id): ?array {
    $stmt = db()->prepare("SELECT * FROM managers WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function list_managers(?string $status = null): array {
    if ($status !== null) {
        $stmt = db()->prepare("SELECT * FROM managers WHERE status = ? ORDER BY created_at DESC");
        $stmt->execute([$status]);
    } else {
        $stmt = db()->query("SELECT * FROM managers ORDER BY status, created_at DESC");
    }
    return $stmt->fetchAll();
}

function create_pending_manager(string $email, string $name, string $password): int {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = db()->prepare("
        INSERT INTO managers (email, name, password_hash, status, created_at)
        VALUES (?, ?, ?, 'pending', ?)
    ");
    $stmt->execute([strtolower($email), $name, $hash, time()]);
    return (int)db()->lastInsertId();
}

function validate_manager(int $manager_id): bool {
    $stmt = db()->prepare("UPDATE managers SET status = 'active', validated_at = ?, rejection_reason = '' WHERE id = ?");
    return $stmt->execute([time(), $manager_id]);
}

function reject_manager(int $manager_id, string $reason): bool {
    $stmt = db()->prepare("UPDATE managers SET status = 'rejected', rejection_reason = ? WHERE id = ?");
    return $stmt->execute([$reason, $manager_id]);
}

function delete_manager(int $manager_id): bool {
    $stmt = db()->prepare("DELETE FROM managers WHERE id = ?");
    return $stmt->execute([$manager_id]);
}

// --- Magic-link manager -------------------------------------------------

function issue_manager_magic_link(int $manager_id): string {
    $token = bin2hex(random_bytes(24));
    $hash  = hash('sha256', $token);
    $ttl   = (int)($GLOBALS['CONFIG']['magic_link_ttl'] ?? 3600);
    $stmt = db()->prepare("INSERT INTO manager_magic_links (manager_id, token_hash, expires_at) VALUES (?, ?, ?)");
    $stmt->execute([$manager_id, $hash, time() + $ttl]);
    return $token;
}

function consume_manager_magic_link(string $token): ?int {
    if ($token === '') return null;
    $hash = hash('sha256', $token);
    $pdo  = db();
    $stmt = $pdo->prepare("SELECT id, manager_id, expires_at, used_at FROM manager_magic_links WHERE token_hash = ?");
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    if (!$row) return null;
    if ($row['used_at']) return null;
    if ((int)$row['expires_at'] < time()) return null;
    $upd = $pdo->prepare("UPDATE manager_magic_links SET used_at = ? WHERE id = ?");
    $upd->execute([time(), $row['id']]);
    return (int)$row['manager_id'];
}
