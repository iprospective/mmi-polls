<?php
// Controller public : vue d'un sondage, magic-link login/logout.
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/mailer.php';
require_once __DIR__ . '/../services/polls.php';
require_once __DIR__ . '/../services/participants.php';
require_once __DIR__ . '/../services/votes.php';
require_once __DIR__ . '/../services/assignments.php';

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
