<?php
// Controller : email de contact + envoi de notifications d'astreintes.
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/mailer.php';
require_once __DIR__ . '/../services/polls.php';
require_once __DIR__ . '/../services/assignments.php';
require_once __DIR__ . '/../services/notifications.php';
require_once __DIR__ . '/../services/activity_log.php';

function route_admin_test_notification(string $uuid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    $email  = trim((string)($_POST['test_email'] ?? ''));
    $custom = trim((string)($_POST['message']    ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_set('err', 'Email de test invalide.');
        redirect('/admin/polls/' . $uuid . '/assignments');
    }
    try {
        send_test_notification_email($poll, $email, $custom);
        flash_set('ok', "Email de test envoyé à $email. Vérifiez la réception (et les spams).");
    } catch (Throwable $e) {
        mail_log($email, '[test notif failed] ' . $e->getMessage(), '');
        flash_set('err', "Échec de l'envoi : " . $e->getMessage());
    }
    redirect('/admin/polls/' . $uuid . '/assignments');
}

function route_admin_set_contact_email(string $uuid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    $email = trim((string)($_POST['contact_email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_set('err', 'Email de contact invalide.');
        redirect('/admin/polls/' . $uuid . '/settings');
    }
    $upd = db()->prepare("UPDATE polls SET contact_email = ? WHERE id = ?");
    $upd->execute([strtolower($email), $poll['id']]);
    log_activity((int)$poll['id'], 'contact_email_update', ['target' => $email]);
    flash_set('ok', 'Email de contact mis à jour.');
    redirect('/admin/polls/' . $uuid . '/settings');
}

/**
 * Override manager : confirme/conteste/réinitialise une notification
 * pour un·e participant·e, sans passer par l'email. Cas d'usage : la
 * personne n'a pas internet, le manager a eu sa réponse de vive voix.
 */
function route_admin_override_notification(string $uuid, string $pid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    $participant_id = (int)$pid;
    $action = (string)($_POST['action'] ?? '');
    $reply  = trim((string)($_POST['reply'] ?? ''));

    // Vérifie que la personne existe bien dans ce sondage.
    $check = db()->prepare("SELECT id, name, email FROM participants WHERE id = ? AND poll_id = ?");
    $check->execute([$participant_id, (int)$poll['id']]);
    $p = $check->fetch();
    if (!$p) not_found();
    $who = $p['name'] !== '' ? $p['name'] : $p['email'];

    if ($action === 'confirm') {
        manager_set_notification_status((int)$poll['id'], $participant_id, 'confirmed');
        log_activity((int)$poll['id'], 'notification_override', [
            'target'  => $who,
            'payload' => ['status' => 'confirmed'],
        ]);
        flash_set('ok', "$who marqué·e comme ayant confirmé (hors-mail).");
    } elseif ($action === 'contest') {
        if ($reply === '') $reply = '[Signalé hors-mail par l\'organisateur·rice]';
        manager_set_notification_status((int)$poll['id'], $participant_id, 'contested', $reply);
        log_activity((int)$poll['id'], 'notification_override', [
            'target'  => $who,
            'payload' => ['status' => 'contested', 'reply' => $reply],
        ]);
        flash_set('ok', "$who marqué·e comme ayant signalé un problème.");
    } elseif ($action === 'reset') {
        if (manager_reset_notification_status((int)$poll['id'], $participant_id)) {
            log_activity((int)$poll['id'], 'notification_override', [
                'target'  => $who,
                'payload' => ['status' => 'reset'],
            ]);
            flash_set('ok', "Statut de $who réinitialisé (en attente de réponse).");
        } else {
            flash_set('err', "Pas de notification à réinitialiser pour $who.");
        }
    } else {
        flash_set('err', 'Action invalide.');
    }
    redirect('/admin/polls/' . $uuid . '/assignments');
}

function route_admin_send_notifications(string $uuid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
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
        $token = issue_notification((int)$poll['id'], (int)$p['id']);
        try {
            send_notification_email($poll, $p, $assigns, $token, $custom);
            $sent++;
        } catch (Throwable $e) {
            $errors[] = $p['email'] . ' : ' . $e->getMessage();
            mail_log($p['email'], '[notification failed] ' . $e->getMessage(), '');
        }
    }

    log_activity((int)$poll['id'], 'notification_send', [
        'target' => "$sent destinataire" . ($sent > 1 ? 's' : ''),
        'payload' => ['target_mode' => $target, 'errors' => $errors],
    ]);
    flash_set('ok', "Notifications envoyées à $sent destinataire(s).");
    if ($errors) flash_set('err', 'Échecs : ' . implode(', ', $errors));
    redirect('/admin/polls/' . $uuid . '/assignments');
}
