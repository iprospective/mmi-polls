<?php
// Controller : page "Mes choix" du participant + save/delete de ses votes.
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../services/polls.php';
require_once __DIR__ . '/../services/assignments.php';
require_once __DIR__ . '/../services/ical.php';
require_once __DIR__ . '/../services/activity_log.php';
require_once __DIR__ . '/../services/geocoder.php';

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
    // Mode brouillon : on ne révèle pas les astreintes côté participant
    // tant que le sondage n'est pas publié.
    $my_assigns = empty($poll['assignments_public'])
        ? []
        : assignments_for_participant((int)$poll['id'], (int)$participant['id']);
    $ical_token = $my_assigns ? get_or_create_ical_token((int)$participant['id']) : '';
    render('poll/me', [
        'page_title' => 'Mes choix — ' . $poll['title'],
        'poll' => $poll,
        'dates' => $dates,
        'participant' => $participant,
        'myvotes' => $myvotes,
        'my_assigns' => $my_assigns,
        'ical_token' => $ical_token,
    ]);
}

function route_poll_save_votes(string $uuid): void {
    $poll = find_poll($uuid);
    if (poll_is_closed($poll)) {
        flash_set('err', 'Ce sondage est clos, vos disponibilités ne sont plus modifiables.');
        redirect('/p/' . $uuid . '/me');
    }
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

    // Adresse + géocodage : uniquement si la feature est active. Sinon
    // on ne touche pas aux valeurs existantes (préserve les données
    // d'un éventuel basculement off→on plus tard).
    $address  = $participant['address']          ?? '';
    $lat      = $participant['latitude']         ?? null;
    $lng      = $participant['longitude']        ?? null;
    $geocoded = (string)($participant['geocoded_address'] ?? '');
    $geo_msg  = '';
    if (poll_addresses_enabled($poll)) {
        $address = trim((string)($_POST['address'] ?? ''));
        if ($address !== (string)($participant['address'] ?? '')) {
            $lat = null; $lng = null; $geocoded = '';
            if ($address !== '') {
                $geo = geocode($address);
                if ($geo) {
                    $lat = $geo['lat']; $lng = $geo['lng'];
                    $geocoded = $geo['display_name'];
                    $geo_msg  = ' 📍 ' . $geo['display_name'];
                } else {
                    $geo_msg = ' (adresse enregistrée mais pas géolocalisée)';
                }
            }
        }
    }

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

    // Normalise le nouveau jeu de votes (filtré, trié) puis charge le courant
    // pour détecter si les votes ont effectivement changé. votes_updated_at ne
    // doit pas être bumpé si la personne a seulement modifié son nom / tél /
    // moyen de contact.
    $new_votes = [];
    foreach ($votes as $cid => $val) {
        $cid = (int)$cid;
        if (!isset($valid_ids[$cid])) continue;
        if (!in_array($val, ['yes', 'no', 'maybe'], true)) continue;
        $new_votes[$cid] = $val;
    }
    ksort($new_votes);
    $cur_v = $pdo->prepare("SELECT choice_id, value FROM votes WHERE participant_id = ?");
    $cur_v->execute([$participant['id']]);
    $current_votes = [];
    foreach ($cur_v as $row) $current_votes[(int)$row['choice_id']] = $row['value'];
    ksort($current_votes);
    $votes_changed = ($current_votes !== $new_votes);

    $pdo->beginTransaction();
    if ($votes_changed) {
        $upd = $pdo->prepare("UPDATE participants SET name = ?, phone = ?, contact_method = ?, address = ?, latitude = ?, longitude = ?, geocoded_address = ?, votes_updated_at = ? WHERE id = ?");
        $upd->execute([$name, $phone, $contact_method, $address, $lat, $lng, $geocoded, time(), $participant['id']]);
        $del = $pdo->prepare("DELETE FROM votes WHERE participant_id = ?");
        $del->execute([$participant['id']]);
        $ins = $pdo->prepare("INSERT INTO votes (participant_id, choice_id, value) VALUES (?, ?, ?)");
        foreach ($new_votes as $cid => $val) {
            $ins->execute([$participant['id'], $cid, $val]);
        }
    } else {
        $upd = $pdo->prepare("UPDATE participants SET name = ?, phone = ?, contact_method = ?, address = ?, latitude = ?, longitude = ?, geocoded_address = ? WHERE id = ?");
        $upd->execute([$name, $phone, $contact_method, $address, $lat, $lng, $geocoded, $participant['id']]);
    }
    $pdo->commit();

    if ($votes_changed) {
        log_activity((int)$poll['id'], 'vote_save', [
            'actor_type'  => 'participant',
            'actor_id'    => (int)$participant['id'],
            'actor_label' => $name !== '' ? $name : $participant['email'],
            'target'      => count($new_votes) . ' votes',
        ]);
    }
    $msg = $votes_changed ? 'Choix enregistrés.' : 'Profil enregistré (votes inchangés).';
    if ($geo_msg) $msg .= $geo_msg;
    flash_set('ok', $msg);
    redirect('/p/' . $uuid . '/me');
}

function route_poll_my_calendar(string $uuid): void {
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
    if (empty($poll['assignments_public'])) {
        flash_set('err', 'Les astreintes ne sont pas encore publiées.');
        redirect('/p/' . $uuid . '/me');
    }

    // Mois affiché
    $month = (string)($_GET['month'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
    [$year, $mon] = array_map('intval', explode('-', $month));
    $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $mon));

    $rows = $pdo->prepare("
        SELECT d.day, c.label, c.sort_order AS c_order, a.role
        FROM assignments a
        JOIN poll_choices c ON c.id = a.choice_id
        JOIN poll_dates   d ON d.id = c.date_id
        WHERE d.poll_id = ? AND a.participant_id = ?
        ORDER BY d.day, c.sort_order, c.id
    ");
    $rows->execute([$poll['id'], $participant['id']]);
    $self_name = $participant['name'] !== '' ? $participant['name'] : explode('@', $participant['email'])[0];
    $by_day = [];
    foreach ($rows as $r) {
        $by_day[$r['day']][$r['label']][$r['role']] = $self_name;
    }

    $span = $pdo->prepare("SELECT MIN(day) AS min_d, MAX(day) AS max_d FROM poll_dates WHERE poll_id = ?");
    $span->execute([$poll['id']]);
    $sp = $span->fetch();

    render('poll/me_calendar', [
        'page_title' => 'Mon calendrier — ' . $poll['title'],
        'poll' => $poll,
        'participant' => $participant,
        'first' => $first,
        'prev_month' => $first->modify('-1 month')->format('Y-m'),
        'next_month' => $first->modify('+1 month')->format('Y-m'),
        'by_day' => $by_day,
        'poll_min' => $sp['min_d'] ?? '',
        'poll_max' => $sp['max_d'] ?? '',
    ]);
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
