<?php
// Controller : page de confirmation/contestation des astreintes (accès par token).
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/mailer.php';
require_once __DIR__ . '/../services/polls.php';
require_once __DIR__ . '/../services/assignments.php';
require_once __DIR__ . '/../services/notifications.php';

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
