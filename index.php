<?php
declare(strict_types=1);

// Front controller. Serve via:  php -S 127.0.0.1:8000 index.php
// (Static assets in /public are served by the built-in router below.)

if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($path !== '/'
        && strpos($path, '..') === false
        && strpos($path, '/public/') === 0
        && file_exists(__DIR__ . $path)) {
        return false; // let built-in server serve the asset
    }
}

$GLOBALS['CONFIG'] = require __DIR__ . '/config.php';

require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/helpers.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/mailer.php';
require __DIR__ . '/lib/html_sanitize.php';

session_name('mmidate');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();

// --- Routing -----------------------------------------------------------------

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
if ($method === 'POST') csrf_check();

$routes = [
    ['GET',  '#^/$#',                                          'route_home'],

    ['GET',  '#^/admin/login$#',                               'route_admin_login_form'],
    ['POST', '#^/admin/login$#',                               'route_admin_login'],
    ['POST', '#^/admin/logout$#',                              'route_admin_logout'],
    ['GET',  '#^/admin$#',                                     'route_admin_list'],
    ['POST', '#^/admin/polls$#',                               'route_admin_create_poll'],
    ['GET',  '#^/admin/polls/([0-9a-f-]+)$#',                  'route_admin_poll'],
    ['POST', '#^/admin/polls/([0-9a-f-]+)$#',                  'route_admin_update_poll'],
    ['POST', '#^/admin/polls/([0-9a-f-]+)/delete$#',           'route_admin_delete_poll'],
    ['GET',  '#^/admin/polls/([0-9a-f-]+)/dates$#',            'route_admin_dates'],
    ['GET',  '#^/admin/polls/([0-9a-f-]+)/settings$#',         'route_admin_settings'],
    ['POST', '#^/admin/polls/([0-9a-f-]+)/dates$#',            'route_admin_add_date'],
    ['POST', '#^/admin/polls/([0-9a-f-]+)/dates/(\d+)/delete$#', 'route_admin_delete_date'],
    ['POST', '#^/admin/polls/([0-9a-f-]+)/dates/(\d+)/choices$#', 'route_admin_add_choice'],
    ['POST', '#^/admin/polls/([0-9a-f-]+)/choices/(\d+)/delete$#', 'route_admin_delete_choice'],
    ['POST', '#^/admin/polls/([0-9a-f-]+)/participants/(\d+)/delete$#', 'route_admin_delete_participant'],
    ['POST', '#^/admin/polls/([0-9a-f-]+)/participants$#',          'route_admin_create_participant'],
    ['GET',  '#^/admin/polls/([0-9a-f-]+)/participants/(\d+)$#',    'route_admin_edit_participant'],
    ['POST', '#^/admin/polls/([0-9a-f-]+)/participants/(\d+)$#',    'route_admin_update_participant'],
    ['GET',  '#^/admin/polls/([0-9a-f-]+)/assignments$#',           'route_admin_assignments'],
    ['POST', '#^/admin/polls/([0-9a-f-]+)/assignments$#',           'route_admin_save_assignments'],
    ['POST', '#^/admin/polls/([0-9a-f-]+)/assignments/auto-fill$#', 'route_admin_auto_fill_assignments'],
    ['POST', '#^/admin/polls/([0-9a-f-]+)/assignments/clear$#',     'route_admin_clear_assignments'],
    ['GET',  '#^/admin/polls/([0-9a-f-]+)/participants$#',          'route_admin_participants_list'],
    ['GET',  '#^/admin/polls/([0-9a-f-]+)/participants/(\d+)/calendar$#', 'route_admin_participant_calendar'],
    ['POST', '#^/admin/polls/([0-9a-f-]+)/contact-email$#',         'route_admin_set_contact_email'],
    ['POST', '#^/admin/polls/([0-9a-f-]+)/assignments/notify$#',    'route_admin_send_notifications'],

    ['GET',  '#^/p/([0-9a-f-]+)$#',                            'route_poll_show'],
    ['GET',  '#^/p/([0-9a-f-]+)/login$#',                      'route_poll_login_form'],
    ['POST', '#^/p/([0-9a-f-]+)/login$#',                      'route_poll_send_link'],
    ['GET',  '#^/p/([0-9a-f-]+)/auth$#',                       'route_poll_consume_link'],
    ['POST', '#^/p/([0-9a-f-]+)/logout$#',                     'route_poll_logout'],
    ['GET',  '#^/p/([0-9a-f-]+)/me$#',                         'route_poll_me'],
    ['POST', '#^/p/([0-9a-f-]+)/me$#',                         'route_poll_save_votes'],
    ['POST', '#^/p/([0-9a-f-]+)/me/delete$#',                  'route_poll_delete_votes'],
    ['GET',  '#^/p/([0-9a-f-]+)/confirm$#',                    'route_poll_confirm_get'],
    ['POST', '#^/p/([0-9a-f-]+)/confirm$#',                    'route_poll_confirm_post'],
];

foreach ($routes as [$m, $pattern, $fn]) {
    if ($m !== $method) continue;
    if (preg_match($pattern, $path, $m_)) {
        array_shift($m_);
        $fn(...$m_);
        exit;
    }
}
not_found();

// --- Helpers -----------------------------------------------------------------

function find_poll(string $uuid): array {
    $stmt = db()->prepare("SELECT * FROM polls WHERE uuid = ?");
    $stmt->execute([$uuid]);
    $poll = $stmt->fetch();
    if (!$poll) not_found();
    return $poll;
}

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

function poll_participants(int $poll_id): array {
    $stmt = db()->prepare("SELECT * FROM participants WHERE poll_id = ? ORDER BY created_at, id");
    $stmt->execute([$poll_id]);
    return $stmt->fetchAll();
}

function poll_votes_map(int $poll_id): array {
    $stmt = db()->prepare("
        SELECT v.participant_id, v.choice_id, v.value
        FROM votes v
        JOIN participants p ON p.id = v.participant_id
        WHERE p.poll_id = ?
    ");
    $stmt->execute([$poll_id]);
    $map = [];
    foreach ($stmt as $r) {
        $map[(int)$r['participant_id']][(int)$r['choice_id']] = $r['value'];
    }
    return $map;
}

// --- Routes: home ------------------------------------------------------------

function route_home(): void {
    if (is_admin()) redirect('/admin');
    render('home', ['page_title' => 'mmidate']);
}

// --- Routes: admin -----------------------------------------------------------

function route_admin_login_form(): void {
    if (is_admin()) redirect('/admin');
    render('admin/login', ['page_title' => 'Connexion admin']);
}

function route_admin_login(): void {
    $u = (string)($_POST['username'] ?? '');
    $p = (string)($_POST['password'] ?? '');
    if (admin_login($u, $p)) {
        flash_set('ok', 'Connecté.');
        redirect('/admin');
    }
    flash_set('err', 'Identifiants invalides.');
    redirect('/admin/login');
}

function route_admin_logout(): void {
    admin_logout();
    redirect('/');
}

function route_admin_list(): void {
    require_admin();
    $polls = db()->query("SELECT * FROM polls ORDER BY created_at DESC")->fetchAll();
    render('admin/list', ['page_title' => 'Sondages', 'polls' => $polls, 'include_editor' => true]);
}

function route_admin_create_poll(): void {
    require_admin();
    $title = trim((string)($_POST['title'] ?? ''));
    $desc  = sanitize_html((string)($_POST['description'] ?? ''));
    if ($title === '') {
        flash_set('err', 'Titre obligatoire.');
        redirect('/admin');
    }
    $uuid = uuid_v4();
    $stmt = db()->prepare("INSERT INTO polls (uuid, title, description, created_at) VALUES (?, ?, ?, ?)");
    $stmt->execute([$uuid, $title, $desc, time()]);
    redirect('/admin/polls/' . $uuid);
}

function route_admin_poll(string $uuid): void {
    require_admin();
    $poll = find_poll($uuid);
    $dates = poll_structure((int)$poll['id']);
    $participants = poll_participants((int)$poll['id']);
    $votes = poll_votes_map((int)$poll['id']);
    $assigns = poll_assignments_map((int)$poll['id']);
    render('admin/poll', [
        'page_title' => $poll['title'],
        'poll' => $poll,
        'dates' => $dates,
        'participants' => $participants,
        'votes' => $votes,
        'assigns' => $assigns,
    ]);
}

function route_admin_update_poll(string $uuid): void {
    require_admin();
    $poll = find_poll($uuid);
    $title = trim((string)($_POST['title'] ?? ''));
    $desc  = sanitize_html((string)($_POST['description'] ?? ''));
    if ($title === '') {
        flash_set('err', 'Titre obligatoire.');
        redirect('/admin/polls/' . $uuid . '/settings');
    }
    $stmt = db()->prepare("UPDATE polls SET title = ?, description = ? WHERE id = ?");
    $stmt->execute([$title, $desc, $poll['id']]);
    flash_set('ok', 'Sondage mis à jour.');
    redirect('/admin/polls/' . $uuid . '/settings');
}

function route_admin_dates(string $uuid): void {
    require_admin();
    $poll = find_poll($uuid);
    $dates = poll_structure((int)$poll['id']);
    render('admin/dates', [
        'page_title' => 'Dates & créneaux — ' . $poll['title'],
        'poll' => $poll,
        'dates' => $dates,
    ]);
}

function route_admin_settings(string $uuid): void {
    require_admin();
    $poll = find_poll($uuid);
    render('admin/settings', [
        'page_title' => 'Paramètres — ' . $poll['title'],
        'poll' => $poll,
        'include_editor' => true,
    ]);
}

function route_admin_delete_poll(string $uuid): void {
    require_admin();
    $poll = find_poll($uuid);
    $stmt = db()->prepare("DELETE FROM polls WHERE id = ?");
    $stmt->execute([$poll['id']]);
    flash_set('ok', 'Sondage supprimé.');
    redirect('/admin');
}

function route_admin_add_date(string $uuid): void {
    require_admin();
    $poll = find_poll($uuid);
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

function route_admin_delete_date(string $uuid, string $date_id): void {
    require_admin();
    $poll = find_poll($uuid);
    $stmt = db()->prepare("DELETE FROM poll_dates WHERE id = ? AND poll_id = ?");
    $stmt->execute([(int)$date_id, $poll['id']]);
    flash_set('ok', 'Date supprimée.');
    redirect('/admin/polls/' . $uuid . '/dates');
}

function route_admin_add_choice(string $uuid, string $date_id): void {
    require_admin();
    $poll = find_poll($uuid);
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
    require_admin();
    $poll = find_poll($uuid);
    $stmt = db()->prepare("
        DELETE FROM poll_choices
        WHERE id = ?
          AND date_id IN (SELECT id FROM poll_dates WHERE poll_id = ?)
    ");
    $stmt->execute([(int)$choice_id, $poll['id']]);
    flash_set('ok', 'Créneau supprimé.');
    redirect('/admin/polls/' . $uuid . '/dates');
}

function route_admin_delete_participant(string $uuid, string $pid): void {
    require_admin();
    $poll = find_poll($uuid);
    $stmt = db()->prepare("DELETE FROM participants WHERE id = ? AND poll_id = ?");
    $stmt->execute([(int)$pid, $poll['id']]);
    flash_set('ok', 'Participant supprimé.');
    redirect('/admin/polls/' . $uuid . '/participants');
}

function assignments_for_participant(int $poll_id, int $participant_id): array {
    $stmt = db()->prepare("
        SELECT d.day, c.label, a.role
        FROM assignments a
        JOIN poll_choices c ON c.id = a.choice_id
        JOIN poll_dates   d ON d.id = c.date_id
        WHERE d.poll_id = ? AND a.participant_id = ?
        ORDER BY d.day, c.sort_order, c.id
    ");
    $stmt->execute([$poll_id, $participant_id]);
    return $stmt->fetchAll();
}

function poll_notifications_status(int $poll_id): array {
    $stmt = db()->prepare("
        SELECT n.*, p.name, p.email
        FROM notifications n
        JOIN participants p ON p.id = n.participant_id
        WHERE n.poll_id = ?
        ORDER BY n.sent_at DESC, n.id DESC
    ");
    $stmt->execute([$poll_id]);
    return $stmt->fetchAll();
}

function find_notification_by_token(string $token): ?array {
    if ($token === '') return null;
    $hash = hash('sha256', $token);
    $stmt = db()->prepare("
        SELECT n.*,
               p.name  AS participant_name,
               p.email AS participant_email,
               po.uuid AS poll_uuid,
               po.title AS poll_title,
               po.contact_email AS contact_email
        FROM notifications n
        JOIN participants p ON p.id = n.participant_id
        JOIN polls       po ON po.id = n.poll_id
        WHERE n.token_hash = ?
    ");
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function fmt_assignment_line(array $a): string {
    $role = $a['role'] === 'primary' ? 'Principal·e' : 'Suppléant·e';
    return fmt_day($a['day']) . ' — ' . $a['label'] . ' — ' . $role;
}

function send_notification_email(array $poll, array $participant, array $assigns, string $token, string $custom_message): void {
    $app_url = rtrim($GLOBALS['CONFIG']['app_url'], '/');
    $confirm_url = $app_url . '/p/' . $poll['uuid'] . '/confirm?token=' . urlencode($token);
    $poll_url    = $app_url . '/p/' . $poll['uuid'];
    $name = $participant['name'] !== '' ? $participant['name'] : explode('@', $participant['email'])[0];

    $subject = '[mmidate] Vos astreintes pour « ' . $poll['title'] . ' »';
    $body  = "Bonjour $name,\n\n";
    $body .= "Les astreintes du sondage « {$poll['title']} » viennent d'être posées par l'organisateur.\n\n";
    if (!empty($assigns)) {
        $body .= "Vos créneaux :\n";
        foreach ($assigns as $a) $body .= "  • " . fmt_assignment_line($a) . "\n";
        $body .= "\n";
    } else {
        $body .= "Aucun créneau ne vous a été assigné.\n\n";
    }
    if ($custom_message !== '') {
        $body .= "Message de l'organisateur :\n$custom_message\n\n";
    }
    $body .= "Pour confirmer (ou signaler un problème) :\n$confirm_url\n\n";
    $body .= "Voir l'ensemble du sondage :\n$poll_url\n\n";
    $body .= "Merci !\n";
    send_mail($participant['email'], $subject, $body);
}

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

function route_admin_assignments(string $uuid): void {
    require_admin();
    $poll = find_poll($uuid);
    $dates        = poll_structure((int)$poll['id']);
    $participants = poll_participants((int)$poll['id']);
    $votes        = poll_votes_map((int)$poll['id']);
    $assigns      = poll_assignments_map((int)$poll['id']);
    $notifs       = poll_notifications_status((int)$poll['id']);

    // Participants ayant au moins une assignation (pour le formulaire de notification).
    $assigned_ids = [];
    foreach ($assigns as $by_role) {
        foreach ($by_role as $pid) $assigned_ids[(int)$pid] = true;
    }
    $assigned_participants = array_values(array_filter($participants,
        fn($p) => isset($assigned_ids[(int)$p['id']])));

    render('admin/assignments', [
        'page_title' => 'Astreintes — ' . $poll['title'],
        'poll' => $poll,
        'dates' => $dates,
        'participants' => $participants,
        'assigned_participants' => $assigned_participants,
        'votes' => $votes,
        'assigns' => $assigns,
        'notifs' => $notifs,
        'include_assignments' => true,
    ]);
}

function route_admin_save_assignments(string $uuid): void {
    require_admin();
    $poll = find_poll($uuid);
    $input = $_POST['assignments'] ?? [];
    if (!is_array($input)) $input = [];

    $pdo = db();

    // Whitelist des choix valides pour ce sondage.
    $vc = $pdo->prepare("
        SELECT c.id FROM poll_choices c
        JOIN poll_dates d ON d.id = c.date_id
        WHERE d.poll_id = ?
    ");
    $vc->execute([$poll['id']]);
    $valid_choice_ids = array_flip(array_map('intval', array_column($vc->fetchAll(), 'id')));

    // Whitelist des participants du sondage.
    $vp = $pdo->prepare("SELECT id FROM participants WHERE poll_id = ?");
    $vp->execute([$poll['id']]);
    $valid_part_ids = array_flip(array_map('intval', array_column($vp->fetchAll(), 'id')));

    $pdo->beginTransaction();
    $del = $pdo->prepare("
        DELETE FROM assignments
        WHERE choice_id IN (
            SELECT c.id FROM poll_choices c
            JOIN poll_dates d ON d.id = c.date_id
            WHERE d.poll_id = ?
        )
    ");
    $del->execute([$poll['id']]);

    $ins = $pdo->prepare("INSERT INTO assignments (choice_id, role, participant_id) VALUES (?, ?, ?)");
    foreach ($input as $cid => $role_map) {
        $cid = (int)$cid;
        if (!isset($valid_choice_ids[$cid])) continue;
        if (!is_array($role_map)) continue;

        $prim = (int)($role_map['primary'] ?? 0);
        $back = (int)($role_map['backup']  ?? 0);
        if ($prim > 0 && !isset($valid_part_ids[$prim])) $prim = 0;
        if ($back > 0 && !isset($valid_part_ids[$back])) $back = 0;
        // Anti-doublon : interdit la même personne aux deux rôles.
        // Si conflit, on garde le principal et on retire le suppléant.
        if ($prim > 0 && $prim === $back) $back = 0;

        if ($prim > 0) $ins->execute([$cid, 'primary', $prim]);
        if ($back > 0) $ins->execute([$cid, 'backup',  $back]);
    }
    $pdo->commit();
    flash_set('ok', 'Astreintes enregistrées.');
    redirect('/admin/polls/' . $uuid . '/assignments');
}

function route_admin_auto_fill_assignments(string $uuid): void {
    require_admin();
    $poll = find_poll($uuid);
    $pdo = db();

    // 1. État existant : assignations posées, votes par créneau,
    //    crédits "Oui" et "Peut-être" par personne.
    $assigns_stmt = $pdo->prepare("
        SELECT a.choice_id, a.role, a.participant_id, d.day
        FROM assignments a
        JOIN poll_choices c ON c.id = a.choice_id
        JOIN poll_dates   d ON d.id = c.date_id
        WHERE d.poll_id = ?
    ");
    $assigns_stmt->execute([$poll['id']]);
    $existing = [];   // [choice_id][role] => pid
    $counts   = [];   // [pid] => assignations courantes
    $last_ts  = [];   // [pid] => epoch du jour de la dernière assignation
    foreach ($assigns_stmt as $r) {
        $cid = (int)$r['choice_id'];
        $pid = (int)$r['participant_id'];
        $existing[$cid][$r['role']] = $pid;
        $counts[$pid] = ($counts[$pid] ?? 0) + 1;
        $ts = strtotime($r['day']) ?: 0;
        if (!isset($last_ts[$pid]) || $ts > $last_ts[$pid]) $last_ts[$pid] = $ts;
    }

    $choices_stmt = $pdo->prepare("
        SELECT c.id AS choice_id, c.label, d.day, c.sort_order AS c_order
        FROM poll_choices c
        JOIN poll_dates d ON d.id = c.date_id
        WHERE d.poll_id = ?
    ");
    $choices_stmt->execute([$poll['id']]);
    $choices = $choices_stmt->fetchAll();

    $yes_by_choice = [];
    $maybe_by_choice = [];
    $yes_credits   = [];     // [pid] => nb total de votes 'yes'
    $maybe_credits = [];     // [pid] => nb total de votes 'maybe'
    $votes_stmt = $pdo->prepare("
        SELECT v.choice_id, v.participant_id, v.value
        FROM votes v
        JOIN poll_choices c ON c.id = v.choice_id
        JOIN poll_dates   d ON d.id = c.date_id
        WHERE d.poll_id = ?
    ");
    $votes_stmt->execute([$poll['id']]);
    foreach ($votes_stmt as $r) {
        $cid = (int)$r['choice_id'];
        $pid = (int)$r['participant_id'];
        if ($r['value'] === 'yes') {
            $yes_by_choice[$cid][] = $pid;
            $yes_credits[$pid] = ($yes_credits[$pid] ?? 0) + 1;
        } elseif ($r['value'] === 'maybe') {
            $maybe_by_choice[$cid][] = $pid;
            $maybe_credits[$pid] = ($maybe_credits[$pid] ?? 0) + 1;
        }
    }

    // 2. Ordre des créneaux : priorité de libellé puis chronologique.
    $priority = $GLOBALS['CONFIG']['auto_fill']['priority']
        ?? ['Nuit', 'Soirée', 'Journée'];
    $rank_of = [];
    foreach ($priority as $i => $label) $rank_of[mb_strtolower($label)] = $i;
    $label_rank = function (string $label) use ($rank_of) {
        return $rank_of[mb_strtolower($label)] ?? 999;
    };
    usort($choices, function ($a, $b) use ($label_rank) {
        $ra = $label_rank($a['label']);
        $rb = $label_rank($b['label']);
        if ($ra !== $rb) return $ra - $rb;
        if ($a['day'] !== $b['day']) return strcmp($a['day'], $b['day']);
        return (int)$a['c_order'] - (int)$b['c_order'];
    });

    // Capacité effective = crédits Oui + 0.5 × Peut-être (mini 1 pour
    // éviter une division par zéro chez les voteurs "maybe-only").
    $capacity_of = static function (int $pid, array $yes_credits, array $maybe_credits): float {
        $cap = ($yes_credits[$pid] ?? 0) + 0.5 * ($maybe_credits[$pid] ?? 0);
        return $cap > 0 ? $cap : 1.0;
    };

    // 3. Algorithme :
    //
    //    Pass 1 : on remplit TOUS les principaux d'abord (priorité de
    //    libellé puis chronologique). Pass 2 : tous les suppléants.
    //    Cf. demande explicite : on veut au moins un principal partout
    //    avant de poser le moindre suppléant.
    //
    //    Pour chaque créneau, on classe les candidats (Oui d'abord,
    //    sinon Peut-être) en 4 tiers selon usage = count / capacité :
    //      tier 0 : < 50 %   → prioritaire
    //      tier 1 : 50–75 %  → moins susceptible
    //      tier 2 : 75–90 %  → encore moins
    //      tier 3 : ≥ 90 %   → dernier recours
    //
    //    Au sein d'un tier, tri PRIMARY = usage_pct ASC.  Ça résout le
    //    déséquilibre Marie/Aude : qq'un avec beaucoup de crédits "Oui"
    //    (Aude, 46) doit être pické plus souvent que qq'un avec peu
    //    de crédits déjà fortement chargé (Marie, 28) pour rester
    //    équilibré en proportion.  À usage égal, dans tier 0, low
    //    yes_credits passe en premier (Cécile boost au 1er tour),
    //    puis spread temporel.
    //
    //    /!\ Les closures sont créées dans la boucle (pas avant) pour
    //    capturer un snapshot frais de $counts et $last_ts à chaque
    //    pick. Bug PHP précédemment : la closure top-level capturait
    //    les valeurs initiales (tout à zéro) une seule fois, rendant
    //    le tri statique sur l'état initial.
    $new_assigns = [];
    foreach (['primary', 'backup'] as $role) {
        $other = $role === 'primary' ? 'backup' : 'primary';
        foreach ($choices as $c) {
            $cid = (int)$c['choice_id'];
            if (isset($existing[$cid][$role])) continue;
            $blocked = $existing[$cid][$other] ?? ($new_assigns[$cid][$other] ?? 0);
            $today_ts = strtotime($c['day']) ?: 0;

            $pick = null;
            foreach ([$yes_by_choice[$cid] ?? [], $maybe_by_choice[$cid] ?? []] as $pool) {
                $cands = array_values(array_filter($pool, fn($p) => (int)$p !== (int)$blocked));
                if (empty($cands)) continue;

                // Bucketing par tier d'usage (snapshot à cet instant).
                $by_tier = [0 => [], 1 => [], 2 => [], 3 => []];
                foreach ($cands as $pid) {
                    $cap = $capacity_of((int)$pid, $yes_credits, $maybe_credits);
                    $usage = ($counts[$pid] ?? 0) / $cap;
                    $tier = $usage < 0.50 ? 0 : ($usage < 0.75 ? 1 : ($usage < 0.90 ? 2 : 3));
                    $by_tier[$tier][] = (int)$pid;
                }

                foreach ([0, 1, 2, 3] as $t) {
                    if (empty($by_tier[$t])) continue;
                    $list = $by_tier[$t];
                    usort($list, function ($a, $b)
                          use ($t, $counts, $last_ts, $yes_credits, $maybe_credits, $today_ts, $capacity_of) {
                        $ua = ($counts[$a] ?? 0) / $capacity_of($a, $yes_credits, $maybe_credits);
                        $ub = ($counts[$b] ?? 0) / $capacity_of($b, $yes_credits, $maybe_credits);
                        if (abs($ua - $ub) > 1e-9) return $ua <=> $ub;
                        // Égalité d'usage : tier 0 → low yes_credits boost
                        $ya = $yes_credits[$a] ?? 0;
                        $yb = $yes_credits[$b] ?? 0;
                        if ($t === 0 && $ya !== $yb) return $ya - $yb;
                        // Spread temporel
                        $la = isset($last_ts[$a]) ? $today_ts - $last_ts[$a] : PHP_INT_MAX;
                        $lb = isset($last_ts[$b]) ? $today_ts - $last_ts[$b] : PHP_INT_MAX;
                        if ($la !== $lb) return $lb <=> $la;
                        if ($ya !== $yb) return $ya - $yb;
                        return $a - $b;
                    });
                    $pick = (int)$list[0];
                    break 2;
                }
            }

            if ($pick === null) continue;
            $new_assigns[$cid][$role] = $pick;
            $counts[$pick] = ($counts[$pick] ?? 0) + 1;
            $last_ts[$pick] = max($last_ts[$pick] ?? 0, $today_ts);
        }
    }

    // 4. Persistance (INSERT only, on ne touche pas aux assignations
    //    déjà saisies à la main).
    $inserted_p = 0;
    $inserted_b = 0;
    if ($new_assigns) {
        $pdo->beginTransaction();
        $ins = $pdo->prepare("INSERT INTO assignments (choice_id, role, participant_id) VALUES (?, ?, ?)");
        foreach ($new_assigns as $cid => $by_role) {
            foreach ($by_role as $role => $pid) {
                try {
                    $ins->execute([$cid, $role, $pid]);
                    if ($role === 'primary') $inserted_p++; else $inserted_b++;
                } catch (Throwable $e) {
                    // PK clash improbable (existing déjà filtré), on ignore.
                }
            }
        }
        $pdo->commit();
    }

    $total = $inserted_p + $inserted_b;
    if ($total > 0) {
        flash_set('ok', "Remplissage automatique : $total créneaux remplis "
            . "($inserted_p principaux, $inserted_b suppléants).");
    } else {
        flash_set('ok', "Aucun créneau à remplir.");
    }
    redirect('/admin/polls/' . $uuid . '/assignments');
}

function route_admin_clear_assignments(string $uuid): void {
    require_admin();
    $poll = find_poll($uuid);
    $stmt = db()->prepare("
        DELETE FROM assignments
        WHERE choice_id IN (
            SELECT c.id FROM poll_choices c
            JOIN poll_dates d ON d.id = c.date_id
            WHERE d.poll_id = ?
        )
    ");
    $stmt->execute([$poll['id']]);
    flash_set('ok', 'Toutes les astreintes ont été supprimées.');
    redirect('/admin/polls/' . $uuid . '/assignments');
}

function route_admin_set_contact_email(string $uuid): void {
    require_admin();
    $poll = find_poll($uuid);
    $email = trim((string)($_POST['contact_email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_set('err', 'Email de contact invalide.');
        redirect('/admin/polls/' . $uuid . '/assignments');
    }
    $upd = db()->prepare("UPDATE polls SET contact_email = ? WHERE id = ?");
    $upd->execute([strtolower($email), $poll['id']]);
    flash_set('ok', 'Email de contact mis à jour.');
    redirect('/admin/polls/' . $uuid . '/assignments');
}

function route_admin_send_notifications(string $uuid): void {
    require_admin();
    $poll = find_poll($uuid);
    $target  = (string)($_POST['target'] ?? 'all');
    $custom  = trim((string)($_POST['message'] ?? ''));
    $only_pid = (int)($_POST['participant_id'] ?? 0);

    $pdo = db();
    if ($target === 'one' && $only_pid > 0) {
        $stmt = $pdo->prepare("SELECT * FROM participants WHERE poll_id = ? AND id = ?");
        $stmt->execute([$poll['id'], $only_pid]);
        $row = $stmt->fetch();
        $targets = $row ? [$row] : [];
    } else {
        $stmt = $pdo->prepare("
            SELECT DISTINCT p.*
            FROM participants p
            JOIN assignments a   ON a.participant_id = p.id
            JOIN poll_choices c  ON c.id = a.choice_id
            JOIN poll_dates   d  ON d.id = c.date_id
            WHERE d.poll_id = ? AND p.poll_id = ?
            ORDER BY p.name, p.email
        ");
        $stmt->execute([$poll['id'], $poll['id']]);
        $targets = $stmt->fetchAll();
    }

    if (!$targets) {
        flash_set('err', 'Aucun destinataire trouvé.');
        redirect('/admin/polls/' . $uuid . '/assignments');
    }

    $sent = 0;
    $errors = [];
    foreach ($targets as $p) {
        $assigns = assignments_for_participant((int)$poll['id'], (int)$p['id']);
        $token = bin2hex(random_bytes(24));
        $hash  = hash('sha256', $token);

        $pdo->beginTransaction();
        $del = $pdo->prepare("DELETE FROM notifications WHERE poll_id = ? AND participant_id = ?");
        $del->execute([$poll['id'], $p['id']]);
        $ins = $pdo->prepare("INSERT INTO notifications (poll_id, participant_id, token_hash, status, sent_at) VALUES (?, ?, ?, 'sent', ?)");
        $ins->execute([$poll['id'], $p['id'], $hash, time()]);
        $pdo->commit();

        try {
            send_notification_email($poll, $p, $assigns, $token, $custom);
            $sent++;
        } catch (Throwable $e) {
            $errors[] = $p['email'] . ' : ' . $e->getMessage();
            mail_log($p['email'], '[notification failed] ' . $e->getMessage(), '');
        }
    }

    flash_set('ok', "Notifications envoyées à $sent destinataire(s).");
    if ($errors) flash_set('err', 'Échecs : ' . implode(', ', $errors));
    redirect('/admin/polls/' . $uuid . '/assignments');
}

function route_poll_confirm_get(string $uuid): void {
    $poll = find_poll($uuid);
    $token = (string)($_GET['token'] ?? '');
    $notif = find_notification_by_token($token);
    if (!$notif || (int)$notif['poll_id'] !== (int)$poll['id']) not_found();
    $assigns = assignments_for_participant((int)$poll['id'], (int)$notif['participant_id']);
    render('poll/confirm', [
        'page_title' => 'Vos astreintes — ' . $poll['title'],
        'poll' => $poll,
        'notif' => $notif,
        'assigns' => $assigns,
        'token' => $token,
    ]);
}

function route_poll_confirm_post(string $uuid): void {
    $poll = find_poll($uuid);
    $token = (string)($_POST['token'] ?? '');
    $notif = find_notification_by_token($token);
    if (!$notif || (int)$notif['poll_id'] !== (int)$poll['id']) not_found();

    $action = (string)($_POST['action'] ?? '');
    $reply  = trim((string)($_POST['reply'] ?? ''));
    $redir  = '/p/' . $uuid . '/confirm?token=' . urlencode($token);

    if ($action === 'confirm') {
        $upd = db()->prepare("UPDATE notifications SET status='confirmed', reply='', responded_at=? WHERE id=?");
        $upd->execute([time(), $notif['id']]);
        flash_set('ok', 'Merci, votre confirmation a bien été enregistrée.');
        redirect($redir);
    }

    if ($action === 'contest') {
        if ($reply === '') {
            flash_set('err', 'Merci d\'indiquer le problème dans le message.');
            redirect($redir);
        }
        $upd = db()->prepare("UPDATE notifications SET status='contested', reply=?, responded_at=? WHERE id=?");
        $upd->execute([$reply, time(), $notif['id']]);

        // Email à l'organisateur (si configuré).
        $contact = trim((string)$poll['contact_email']);
        if ($contact !== '' && filter_var($contact, FILTER_VALIDATE_EMAIL)) {
            $assigns = assignments_for_participant((int)$poll['id'], (int)$notif['participant_id']);
            $name = $notif['participant_name'] !== '' ? $notif['participant_name'] : $notif['participant_email'];
            $subject = '[mmidate] ' . $name . ' signale un problème — ' . $poll['title'];
            $body  = "$name <{$notif['participant_email']}> a contesté ses astreintes pour le sondage « {$poll['title']} ».\n\n";
            $body .= "Ses astreintes actuelles :\n";
            foreach ($assigns as $a) $body .= "  • " . fmt_assignment_line($a) . "\n";
            $body .= "\nSon message :\n---\n$reply\n---\n\n";
            $body .= "Voir : " . rtrim($GLOBALS['CONFIG']['app_url'], '/') . "/admin/polls/{$poll['uuid']}/assignments\n";
            try {
                send_mail($contact, $subject, $body);
            } catch (Throwable $e) {
                mail_log($contact, '[contest notify failed] ' . $e->getMessage(), $body);
            }
        }
        flash_set('ok', 'Votre signalement a été transmis. Merci !');
        redirect($redir);
    }
    redirect($redir);
}

function route_admin_participants_list(string $uuid): void {
    require_admin();
    $poll = find_poll($uuid);
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT
            p.id, p.name, p.email, p.created_at, p.votes_updated_at,
            p.phone, p.contact_method,
            COALESCE(SUM(CASE WHEN v.value='yes'   THEN 1 ELSE 0 END), 0) AS yes_count,
            COALESCE(SUM(CASE WHEN v.value='maybe' THEN 1 ELSE 0 END), 0) AS maybe_count,
            COALESCE(SUM(CASE WHEN v.value='no'    THEN 1 ELSE 0 END), 0) AS no_count,
            COALESCE((SELECT COUNT(*) FROM assignments a WHERE a.participant_id = p.id AND a.role='primary'), 0) AS primary_count,
            COALESCE((SELECT COUNT(*) FROM assignments a WHERE a.participant_id = p.id AND a.role='backup'),  0) AS backup_count,
            (SELECT n.status FROM notifications n WHERE n.poll_id = p.poll_id AND n.participant_id = p.id) AS notif_status,
            (SELECT n.responded_at FROM notifications n WHERE n.poll_id = p.poll_id AND n.participant_id = p.id) AS notif_responded_at
        FROM participants p
        LEFT JOIN votes v ON v.participant_id = p.id
        WHERE p.poll_id = ?
        GROUP BY p.id
        ORDER BY p.name, p.email
    ");
    $stmt->execute([$poll['id']]);
    $rows = $stmt->fetchAll();

    $tc = $pdo->prepare("SELECT COUNT(*) FROM poll_choices c JOIN poll_dates d ON d.id = c.date_id WHERE d.poll_id = ?");
    $tc->execute([$poll['id']]);
    $total_choices = (int)$tc->fetchColumn();

    render('admin/participants', [
        'page_title' => 'Participants — ' . $poll['title'],
        'poll' => $poll,
        'rows' => $rows,
        'total_choices' => $total_choices,
        'include_sortable' => true,
    ]);
}

function route_admin_participant_calendar(string $uuid, string $pid): void {
    require_admin();
    $poll = find_poll($uuid);
    $pdo  = db();
    $stmt = $pdo->prepare("SELECT * FROM participants WHERE id = ? AND poll_id = ?");
    $stmt->execute([(int)$pid, $poll['id']]);
    $participant = $stmt->fetch();
    if (!$participant) not_found();

    $assigns = assignments_for_participant((int)$poll['id'], (int)$participant['id']);
    $cnt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN value='yes'   THEN 1 ELSE 0 END) AS yes_count,
            SUM(CASE WHEN value='maybe' THEN 1 ELSE 0 END) AS maybe_count,
            SUM(CASE WHEN value='no'    THEN 1 ELSE 0 END) AS no_count
        FROM votes WHERE participant_id = ?
    ");
    $cnt->execute([$participant['id']]);
    $vote_counts = $cnt->fetch() ?: ['yes_count' => 0, 'maybe_count' => 0, 'no_count' => 0];

    render('admin/participant_calendar', [
        'page_title' => 'Calendrier — ' . ($participant['name'] !== '' ? $participant['name'] : $participant['email']),
        'poll' => $poll,
        'participant' => $participant,
        'assigns' => $assigns,
        'vote_counts' => $vote_counts,
    ]);
}

function route_admin_create_participant(string $uuid): void {
    require_admin();
    $poll = find_poll($uuid);
    $name  = trim((string)($_POST['name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_set('err', 'Email invalide.');
        redirect('/admin/polls/' . $uuid . '/participants');
    }
    $pdo = db();
    $check = $pdo->prepare("SELECT id FROM participants WHERE poll_id = ? AND email = ?");
    $check->execute([$poll['id'], $email]);
    if ($row = $check->fetch()) {
        flash_set('err', 'Un participant avec cet email existe déjà.');
        redirect('/admin/polls/' . $uuid . '/participants/' . (int)$row['id']);
    }
    $ins = $pdo->prepare("INSERT INTO participants (poll_id, email, name, created_at) VALUES (?, ?, ?, ?)");
    $ins->execute([$poll['id'], $email, $name, time()]);
    $new_id = (int)$pdo->lastInsertId();
    flash_set('ok', 'Participant ajouté. Saisissez maintenant ses disponibilités.');
    redirect('/admin/polls/' . $uuid . '/participants/' . $new_id);
}

function route_admin_edit_participant(string $uuid, string $pid): void {
    require_admin();
    $poll = find_poll($uuid);
    $pdo = db();
    $p = $pdo->prepare("SELECT * FROM participants WHERE id = ? AND poll_id = ?");
    $p->execute([(int)$pid, $poll['id']]);
    $participant = $p->fetch();
    if (!$participant) not_found();
    $dates = poll_structure((int)$poll['id']);
    $v = $pdo->prepare("SELECT choice_id, value FROM votes WHERE participant_id = ?");
    $v->execute([$participant['id']]);
    $myvotes = [];
    foreach ($v as $row) $myvotes[(int)$row['choice_id']] = $row['value'];
    render('admin/participant', [
        'page_title' => 'Édition — ' . ($participant['name'] !== '' ? $participant['name'] : $participant['email']),
        'poll' => $poll,
        'dates' => $dates,
        'participant' => $participant,
        'myvotes' => $myvotes,
    ]);
}

function route_admin_update_participant(string $uuid, string $pid): void {
    require_admin();
    $poll = find_poll($uuid);
    $pdo = db();
    $p = $pdo->prepare("SELECT * FROM participants WHERE id = ? AND poll_id = ?");
    $p->execute([(int)$pid, $poll['id']]);
    $participant = $p->fetch();
    if (!$participant) not_found();

    $name  = trim((string)($_POST['name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $phone = sanitize_phone((string)($_POST['phone'] ?? ''));
    $cm_input = $_POST['contact_method'] ?? [];
    if (!is_array($cm_input)) $cm_input = [$cm_input];
    $cm_valid = array_values(array_unique(array_intersect($cm_input, array_keys(contact_methods()))));
    sort($cm_valid);
    $contact_method = implode(',', $cm_valid);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_set('err', 'Email invalide.');
        redirect('/admin/polls/' . $uuid . '/participants/' . (int)$pid);
    }
    // Detect email collision with another participant of the same poll.
    $coll = $pdo->prepare("SELECT id FROM participants WHERE poll_id = ? AND email = ? AND id != ?");
    $coll->execute([$poll['id'], $email, $participant['id']]);
    if ($coll->fetch()) {
        flash_set('err', 'Un autre participant du sondage utilise déjà cet email.');
        redirect('/admin/polls/' . $uuid . '/participants/' . (int)$pid);
    }

    $votes = $_POST['votes'] ?? [];
    if (!is_array($votes)) $votes = [];
    $valid = $pdo->prepare("
        SELECT c.id FROM poll_choices c
        JOIN poll_dates d ON d.id = c.date_id
        WHERE d.poll_id = ?
    ");
    $valid->execute([$poll['id']]);
    $valid_ids = array_flip(array_map('intval', array_column($valid->fetchAll(), 'id')));

    $pdo->beginTransaction();
    $upd = $pdo->prepare("UPDATE participants SET name = ?, email = ?, phone = ?, contact_method = ?, votes_updated_at = ? WHERE id = ?");
    $upd->execute([$name, $email, $phone, $contact_method, time(), $participant['id']]);
    $del = $pdo->prepare("DELETE FROM votes WHERE participant_id = ?");
    $del->execute([$participant['id']]);
    $ins = $pdo->prepare("INSERT INTO votes (participant_id, choice_id, value) VALUES (?, ?, ?)");
    foreach ($votes as $cid => $val) {
        $cid = (int)$cid;
        if (!isset($valid_ids[$cid])) continue;
        if (!in_array($val, ['yes', 'no', 'maybe'], true)) continue;
        $ins->execute([$participant['id'], $cid, $val]);
    }
    $pdo->commit();
    flash_set('ok', 'Participant mis à jour.');
    redirect('/admin/polls/' . $uuid . '/participants/' . (int)$pid);
}

// --- Routes: public poll -----------------------------------------------------

function route_poll_show(string $uuid): void {
    $poll = find_poll($uuid);
    $dates = poll_structure((int)$poll['id']);
    $participants = poll_participants((int)$poll['id']);
    $votes = poll_votes_map((int)$poll['id']);
    $assigns = poll_assignments_map((int)$poll['id']);
    render('poll/show', [
        'page_title' => $poll['title'],
        'poll' => $poll,
        'dates' => $dates,
        'participants' => $participants,
        'votes' => $votes,
        'assigns' => $assigns,
        'me' => participant_session($uuid),
    ]);
}

function route_poll_login_form(string $uuid): void {
    $poll = find_poll($uuid);
    render('poll/login', ['page_title' => 'Connexion — ' . $poll['title'], 'poll' => $poll, 'sent' => false]);
}

function route_poll_send_link(string $uuid): void {
    $poll = find_poll($uuid);
    $email = trim((string)($_POST['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_set('err', 'Email invalide.');
        redirect('/p/' . $uuid . '/login');
    }
    $email = strtolower($email);
    $token = issue_magic_link((int)$poll['id'], $email);
    $link  = rtrim($GLOBALS['CONFIG']['app_url'], '/') . '/p/' . $uuid . '/auth?token=' . urlencode($token);
    $subject = 'Lien de connexion — ' . $poll['title'];
    $body  = "Bonjour,\n\n";
    $body .= "Voici votre lien de connexion au sondage \"" . $poll['title'] . "\" :\n\n";
    $body .= $link . "\n\n";
    $body .= "Ce lien est valable " . (int)($GLOBALS['CONFIG']['magic_link_ttl'] / 60) . " minutes.\n";
    try {
        send_mail($email, $subject, $body);
    } catch (Throwable $e) {
        mail_log($email, '[SMTP failed: ' . $e->getMessage() . '] ' . $subject, $body);
    }
    render('poll/login', [
        'page_title' => 'Lien envoyé',
        'poll' => $poll,
        'sent' => true,
        'sent_to' => $email,
    ]);
}

function route_poll_consume_link(string $uuid): void {
    $poll = find_poll($uuid);
    $token = (string)($_GET['token'] ?? '');
    $email = consume_magic_link((int)$poll['id'], $token);
    if (!$email) {
        flash_set('err', 'Lien invalide ou expiré.');
        redirect('/p/' . $uuid . '/login');
    }
    $pid = find_or_create_participant((int)$poll['id'], $email);
    participant_login($uuid, $pid, $email);
    flash_set('ok', 'Connecté en tant que ' . $email . '.');
    redirect('/p/' . $uuid . '/me');
}

function route_poll_logout(string $uuid): void {
    participant_logout($uuid);
    redirect('/p/' . $uuid);
}

function route_poll_me(string $uuid): void {
    $poll = find_poll($uuid);
    $auth = participant_session($uuid);
    if (!$auth) redirect('/p/' . $uuid . '/login');
    $pdo = db();
    $p = $pdo->prepare("SELECT * FROM participants WHERE id = ? AND poll_id = ?");
    $p->execute([$auth['participant_id'], $poll['id']]);
    $participant = $p->fetch();
    if (!$participant) {
        participant_logout($uuid);
        redirect('/p/' . $uuid . '/login');
    }
    $dates = poll_structure((int)$poll['id']);
    $myvotes = [];
    $v = $pdo->prepare("SELECT choice_id, value FROM votes WHERE participant_id = ?");
    $v->execute([$participant['id']]);
    foreach ($v as $row) $myvotes[(int)$row['choice_id']] = $row['value'];
    $my_assigns = assignments_for_participant((int)$poll['id'], (int)$participant['id']);
    render('poll/me', [
        'page_title' => 'Mes choix — ' . $poll['title'],
        'poll' => $poll,
        'dates' => $dates,
        'participant' => $participant,
        'myvotes' => $myvotes,
        'my_assigns' => $my_assigns,
    ]);
}

function route_poll_save_votes(string $uuid): void {
    $poll = find_poll($uuid);
    $auth = participant_session($uuid);
    if (!$auth) redirect('/p/' . $uuid . '/login');
    $pdo = db();
    $p = $pdo->prepare("SELECT * FROM participants WHERE id = ? AND poll_id = ?");
    $p->execute([$auth['participant_id'], $poll['id']]);
    $participant = $p->fetch();
    if (!$participant) {
        participant_logout($uuid);
        redirect('/p/' . $uuid . '/login');
    }
    $name = trim((string)($_POST['name'] ?? ''));
    $phone = sanitize_phone((string)($_POST['phone'] ?? ''));
    $cm_input = $_POST['contact_method'] ?? [];
    if (!is_array($cm_input)) $cm_input = [$cm_input];
    $cm_valid = array_values(array_unique(array_intersect($cm_input, array_keys(contact_methods()))));
    sort($cm_valid);
    $contact_method = implode(',', $cm_valid);
    $votes = $_POST['votes'] ?? [];
    if (!is_array($votes)) $votes = [];

    // Restrict choice_ids to this poll.
    $valid = $pdo->prepare("
        SELECT c.id FROM poll_choices c
        JOIN poll_dates d ON d.id = c.date_id
        WHERE d.poll_id = ?
    ");
    $valid->execute([$poll['id']]);
    $valid_ids = array_flip(array_map('intval', array_column($valid->fetchAll(), 'id')));

    $pdo->beginTransaction();
    $upd = $pdo->prepare("UPDATE participants SET name = ?, phone = ?, contact_method = ?, votes_updated_at = ? WHERE id = ?");
    $upd->execute([$name, $phone, $contact_method, time(), $participant['id']]);
    $del = $pdo->prepare("DELETE FROM votes WHERE participant_id = ?");
    $del->execute([$participant['id']]);
    $ins = $pdo->prepare("INSERT INTO votes (participant_id, choice_id, value) VALUES (?, ?, ?)");
    foreach ($votes as $cid => $val) {
        $cid = (int)$cid;
        if (!isset($valid_ids[$cid])) continue;
        if (!in_array($val, ['yes', 'no', 'maybe'], true)) continue;
        $ins->execute([$participant['id'], $cid, $val]);
    }
    $pdo->commit();
    flash_set('ok', 'Choix enregistrés.');
    redirect('/p/' . $uuid . '/me');
}

function route_poll_delete_votes(string $uuid): void {
    $poll = find_poll($uuid);
    $auth = participant_session($uuid);
    if (!$auth) redirect('/p/' . $uuid . '/login');
    $stmt = db()->prepare("DELETE FROM participants WHERE id = ? AND poll_id = ?");
    $stmt->execute([$auth['participant_id'], $poll['id']]);
    participant_logout($uuid);
    flash_set('ok', 'Vos choix ont été supprimés.');
    redirect('/p/' . $uuid);
}
