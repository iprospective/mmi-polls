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
