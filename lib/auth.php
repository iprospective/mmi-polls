<?php

function is_admin(): bool {
    return !empty($_SESSION['is_admin']);
}

function require_admin(): void {
    if (!is_admin()) redirect('/admin/login');
}

function admin_login(string $username, string $password): bool {
    $cfg = $GLOBALS['CONFIG']['admin'];
    $u_ok = hash_equals((string)$cfg['username'], $username);
    $p_ok = hash_equals((string)$cfg['password'], $password);
    if ($u_ok && $p_ok) {
        session_regenerate_id(true);
        $_SESSION['is_admin'] = true;
        return true;
    }
    return false;
}

function admin_logout(): void {
    unset($_SESSION['is_admin']);
    session_regenerate_id(true);
}

function participant_session(string $poll_uuid): ?array {
    return $_SESSION['poll_auth'][$poll_uuid] ?? null;
}

function participant_login(string $poll_uuid, int $participant_id, string $email): void {
    session_regenerate_id(false);
    $_SESSION['poll_auth'][$poll_uuid] = [
        'participant_id' => $participant_id,
        'email' => $email,
    ];
}

function participant_logout(string $poll_uuid): void {
    unset($_SESSION['poll_auth'][$poll_uuid]);
}

function issue_magic_link(int $poll_id, string $email): string {
    $token = bin2hex(random_bytes(24));
    $hash = hash('sha256', $token);
    $ttl  = (int)$GLOBALS['CONFIG']['magic_link_ttl'];
    $stmt = db()->prepare("INSERT INTO magic_links (poll_id, email, token_hash, expires_at) VALUES (?, ?, ?, ?)");
    $stmt->execute([$poll_id, $email, $hash, time() + $ttl]);
    return $token;
}

function consume_magic_link(int $poll_id, string $token): ?string {
    $hash = hash('sha256', $token);
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, email, expires_at, used_at FROM magic_links WHERE poll_id = ? AND token_hash = ?");
    $stmt->execute([$poll_id, $hash]);
    $row = $stmt->fetch();
    if (!$row) return null;
    if ($row['used_at']) return null;
    if ((int)$row['expires_at'] < time()) return null;
    $upd = $pdo->prepare("UPDATE magic_links SET used_at = ? WHERE id = ?");
    $upd->execute([time(), $row['id']]);
    return $row['email'];
}

function find_or_create_participant(int $poll_id, string $email): int {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id FROM participants WHERE poll_id = ? AND email = ?");
    $stmt->execute([$poll_id, $email]);
    $row = $stmt->fetch();
    if ($row) return (int)$row['id'];
    $ins = $pdo->prepare("INSERT INTO participants (poll_id, email, created_at) VALUES (?, ?, ?)");
    $ins->execute([$poll_id, $email, time()]);
    return (int)$pdo->lastInsertId();
}
