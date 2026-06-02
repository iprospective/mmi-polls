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

        CREATE TABLE IF NOT EXISTS assignments (
            choice_id      INTEGER NOT NULL REFERENCES poll_choices(id) ON DELETE CASCADE,
            role           TEXT NOT NULL CHECK (role IN ('primary', 'backup')),
            participant_id INTEGER NOT NULL REFERENCES participants(id) ON DELETE CASCADE,
            PRIMARY KEY (choice_id, role)
        );
        CREATE INDEX IF NOT EXISTS idx_assignments_pid ON assignments(participant_id);

        CREATE TABLE IF NOT EXISTS managers (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            email           TEXT NOT NULL UNIQUE,
            name            TEXT NOT NULL DEFAULT '',
            password_hash   TEXT NOT NULL,
            status          TEXT NOT NULL DEFAULT 'pending'
                              CHECK (status IN ('pending', 'active', 'rejected')),
            rejection_reason TEXT NOT NULL DEFAULT '',
            created_at      INTEGER NOT NULL,
            validated_at    INTEGER
        );

        CREATE TABLE IF NOT EXISTS manager_magic_links (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            manager_id  INTEGER NOT NULL REFERENCES managers(id) ON DELETE CASCADE,
            token_hash  TEXT NOT NULL UNIQUE,
            expires_at  INTEGER NOT NULL,
            used_at     INTEGER
        );

        CREATE TABLE IF NOT EXISTS geocode_cache (
            query_hash   TEXT PRIMARY KEY,
            query_text   TEXT NOT NULL,
            latitude     REAL,
            longitude    REAL,
            display_name TEXT NOT NULL DEFAULT '',
            fetched_at   INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS activity_log (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            poll_id      INTEGER NOT NULL REFERENCES polls(id) ON DELETE CASCADE,
            actor_type   TEXT NOT NULL,    -- 'admin' | 'manager' | 'participant' | 'system'
            actor_id     INTEGER,
            actor_label  TEXT NOT NULL DEFAULT '',
            action       TEXT NOT NULL,
            target       TEXT NOT NULL DEFAULT '',
            payload      TEXT NOT NULL DEFAULT '',
            created_at   INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_activity_poll ON activity_log(poll_id, created_at DESC);

        CREATE TABLE IF NOT EXISTS poll_managers (
            poll_id        INTEGER NOT NULL REFERENCES polls(id)    ON DELETE CASCADE,
            manager_id     INTEGER NOT NULL REFERENCES managers(id) ON DELETE CASCADE,
            added_at       INTEGER NOT NULL,
            added_by_admin INTEGER NOT NULL DEFAULT 0,
            invited_by     INTEGER REFERENCES managers(id) ON DELETE SET NULL,
            PRIMARY KEY (poll_id, manager_id)
        );
        CREATE INDEX IF NOT EXISTS idx_poll_managers_manager ON poll_managers(manager_id);

        CREATE TABLE IF NOT EXISTS notifications (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            poll_id        INTEGER NOT NULL REFERENCES polls(id) ON DELETE CASCADE,
            participant_id INTEGER NOT NULL REFERENCES participants(id) ON DELETE CASCADE,
            token_hash     TEXT NOT NULL UNIQUE,
            status         TEXT NOT NULL DEFAULT 'sent'
                            CHECK (status IN ('sent', 'confirmed', 'contested')),
            reply          TEXT NOT NULL DEFAULT '',
            sent_at        INTEGER NOT NULL,
            responded_at   INTEGER,
            UNIQUE(poll_id, participant_id)
        );
        CREATE INDEX IF NOT EXISTS idx_notif_token ON notifications(token_hash);

        -- Demandes de remplacement : un·e participant·e veut céder une
        -- astreinte. status 'open' = en attente, 'taken' = accepté par
        -- taken_by_pid, 'cancelled' = retiré par requester/manager,
        -- 'expired' = l'astreinte d'origine a disparu (manager l'a
        -- réassignée pendant que la requête tournait).
        CREATE TABLE IF NOT EXISTS swap_requests (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            poll_id        INTEGER NOT NULL REFERENCES polls(id)        ON DELETE CASCADE,
            requester_pid  INTEGER NOT NULL REFERENCES participants(id) ON DELETE CASCADE,
            choice_id      INTEGER NOT NULL REFERENCES poll_choices(id) ON DELETE CASCADE,
            role           TEXT NOT NULL CHECK (role IN ('primary', 'backup')),
            status         TEXT NOT NULL DEFAULT 'open'
                            CHECK (status IN ('open', 'taken', 'cancelled', 'expired')),
            message        TEXT NOT NULL DEFAULT '',
            created_at     INTEGER NOT NULL,
            closed_at      INTEGER,
            taken_by_pid   INTEGER REFERENCES participants(id) ON DELETE SET NULL
        );
        CREATE INDEX IF NOT EXISTS idx_swap_poll_status ON swap_requests(poll_id, status);
        CREATE INDEX IF NOT EXISTS idx_swap_requester   ON swap_requests(requester_pid);

        -- Destinataires d'une demande de remplacement. Un token par
        -- (request, target) : URL personnelle, traçabilité des réponses.
        CREATE TABLE IF NOT EXISTS swap_request_targets (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            request_id     INTEGER NOT NULL REFERENCES swap_requests(id) ON DELETE CASCADE,
            participant_id INTEGER NOT NULL REFERENCES participants(id)  ON DELETE CASCADE,
            token_hash     TEXT NOT NULL UNIQUE,
            sent_at        INTEGER NOT NULL,
            responded_at   INTEGER,
            response       TEXT CHECK (response IN ('accept', 'decline')),
            UNIQUE(request_id, participant_id)
        );
        CREATE INDEX IF NOT EXISTS idx_swap_target_token ON swap_request_targets(token_hash);
    ");

    // Migrations sur la table polls.
    $cols = $pdo->query("PRAGMA table_info(polls)")->fetchAll();
    $present = array_column($cols, 'name');
    if (!in_array('contact_email', $present, true)) {
        $pdo->exec("ALTER TABLE polls ADD COLUMN contact_email TEXT NOT NULL DEFAULT ''");
    }
    if (!in_array('manager_id', $present, true)) {
        // NULL = sondage créé par l'admin global. Sinon, manager créateur·rice.
        $pdo->exec("ALTER TABLE polls ADD COLUMN manager_id INTEGER REFERENCES managers(id) ON DELETE SET NULL");
    }
    if (!in_array('closed_at', $present, true)) {
        // Date YYYY-MM-DD ou '' : si non vide et passée, les participants
        // ne peuvent plus modifier leurs votes (read-only).
        $pdo->exec("ALTER TABLE polls ADD COLUMN closed_at TEXT NOT NULL DEFAULT ''");
    }
    foreach ([
        'start_address'  => "TEXT NOT NULL DEFAULT ''",
        'start_lat'      => "REAL",
        'start_lng'      => "REAL",
        'start_geocoded' => "TEXT NOT NULL DEFAULT ''",
        'end_address'    => "TEXT NOT NULL DEFAULT ''",
        'end_lat'        => "REAL",
        'end_lng'        => "REAL",
        'end_geocoded'   => "TEXT NOT NULL DEFAULT ''",
        // 1 = astreintes visibles côté participant. 0 = brouillon admin only.
        'assignments_public' => "INTEGER NOT NULL DEFAULT 1",
        // 1 = gestion d'adresses activée pour ce sondage (géocodage +
        // distance dans l'algo). Master switch global aussi nécessaire
        // (CONFIG.addresses.enabled). Voir poll_addresses_enabled().
        'addresses_enabled'  => "INTEGER NOT NULL DEFAULT 1",
        // JSON encodant la map label -> {start, end} pour les horaires
        // des créneaux. Vide = fallback sur les défauts (helper
        // poll_slot_hours_map). Format HH:MM. end < start = lendemain.
        'slot_hours' => "TEXT NOT NULL DEFAULT ''",
    ] as $col => $sql_type) {
        if (!in_array($col, $present, true)) {
            $pdo->exec("ALTER TABLE polls ADD COLUMN $col $sql_type");
        }
    }

    // Backfill : tout poll avec un manager_id alimente poll_managers (idempotent).
    $pdo->exec("
        INSERT OR IGNORE INTO poll_managers (poll_id, manager_id, added_at, added_by_admin)
        SELECT id, manager_id, COALESCE(created_at, strftime('%s', 'now')), 0
        FROM polls WHERE manager_id IS NOT NULL
    ");

    // Migrations sur la table participants.
    $cols = $pdo->query("PRAGMA table_info(participants)")->fetchAll();
    $present = array_column($cols, 'name');
    if (!in_array('votes_updated_at', $present, true)) {
        $pdo->exec("ALTER TABLE participants ADD COLUMN votes_updated_at INTEGER NOT NULL DEFAULT 0");
    }
    if (!in_array('phone', $present, true)) {
        $pdo->exec("ALTER TABLE participants ADD COLUMN phone TEXT NOT NULL DEFAULT ''");
    }
    if (!in_array('contact_method', $present, true)) {
        $pdo->exec("ALTER TABLE participants ADD COLUMN contact_method TEXT NOT NULL DEFAULT ''");
    }
    if (!in_array('hidden_in_public', $present, true)) {
        // 0 = visible, 1 = masqué dans la vue publique (admin/manager voit toujours)
        $pdo->exec("ALTER TABLE participants ADD COLUMN hidden_in_public INTEGER NOT NULL DEFAULT 0");
    }
    if (!in_array('ical_token', $present, true)) {
        // Token pour l'abonnement iCal personnel (généré à la 1re demande).
        $pdo->exec("ALTER TABLE participants ADD COLUMN ical_token TEXT NOT NULL DEFAULT ''");
    }
    if (!in_array('assignments_updated_at', $present, true)) {
        // Bumpé à chaque ajout/suppression/changement d'astreinte sur cette
        // personne (save manuel, auto-fill, clear). Si > notification.sent_at,
        // les managers voient un badge « À renotifier » : le contenu envoyé
        // ne correspond plus aux astreintes actuelles.
        $pdo->exec("ALTER TABLE participants ADD COLUMN assignments_updated_at INTEGER NOT NULL DEFAULT 0");
    }
    foreach ([
        'address'          => "TEXT NOT NULL DEFAULT ''",
        'latitude'         => "REAL",
        'longitude'        => "REAL",
        // Adresse normalisée renvoyée par Nominatim (display_name).
        'geocoded_address' => "TEXT NOT NULL DEFAULT ''",
    ] as $col => $sql_type) {
        if (!in_array($col, $present, true)) {
            $pdo->exec("ALTER TABLE participants ADD COLUMN $col $sql_type");
        }
    }
}

function uuid_v4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
