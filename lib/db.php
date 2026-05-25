<?php

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $cfg = $GLOBALS['CONFIG'];
    $path = $cfg['db_path'];
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    db_migrate($pdo);
    return $pdo;
}

function db_migrate(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS polls (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid        TEXT NOT NULL UNIQUE,
            title       TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            created_at  INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS poll_dates (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            poll_id     INTEGER NOT NULL REFERENCES polls(id) ON DELETE CASCADE,
            day         TEXT NOT NULL,
            sort_order  INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS idx_poll_dates_poll ON poll_dates(poll_id, sort_order);

        CREATE TABLE IF NOT EXISTS poll_choices (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            date_id     INTEGER NOT NULL REFERENCES poll_dates(id) ON DELETE CASCADE,
            label       TEXT NOT NULL,
            sort_order  INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS idx_poll_choices_date ON poll_choices(date_id, sort_order);

        CREATE TABLE IF NOT EXISTS participants (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            poll_id     INTEGER NOT NULL REFERENCES polls(id) ON DELETE CASCADE,
            email       TEXT NOT NULL,
            name        TEXT NOT NULL DEFAULT '',
            created_at  INTEGER NOT NULL,
            UNIQUE(poll_id, email)
        );

        CREATE TABLE IF NOT EXISTS votes (
            participant_id INTEGER NOT NULL REFERENCES participants(id) ON DELETE CASCADE,
            choice_id      INTEGER NOT NULL REFERENCES poll_choices(id) ON DELETE CASCADE,
            value          TEXT NOT NULL CHECK(value IN ('yes','no','maybe')),
            PRIMARY KEY (participant_id, choice_id)
        );

        CREATE TABLE IF NOT EXISTS magic_links (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            poll_id     INTEGER NOT NULL REFERENCES polls(id) ON DELETE CASCADE,
            email       TEXT NOT NULL,
            token_hash  TEXT NOT NULL UNIQUE,
            expires_at  INTEGER NOT NULL,
            used_at     INTEGER
        );
    ");
}

function uuid_v4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
