<?php
// Service Notifications : envoi d'emails d'astreintes, lecture/résolution
// par token, formatage des lignes pour les emails.
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/mailer.php';

/**
 * Statut de confirmation par participant·e ASSIGNÉ·E (avec ou sans
 * notification envoyée). Sert à l'UI manager où on peut confirmer/
 * contester hors-mail pour les personnes sans internet.
 *
 * Renvoie pour chaque assigné : participant + notification éventuelle
 * (status, sent_at, responded_at, responded_by, reply) ou tout à null
 * si jamais notifié·e.
 */
function poll_confirmation_status(int $poll_id): array {
    $stmt = db()->prepare("
        SELECT
            p.id, p.name, p.email, p.assignments_updated_at,
            n.id           AS notif_id,
            n.status       AS notif_status,
            n.sent_at      AS notif_sent_at,
            n.responded_at AS notif_responded_at,
            n.responded_by AS notif_responded_by,
            n.reply        AS notif_reply
        FROM participants p
        JOIN (
            SELECT DISTINCT a.participant_id
            FROM assignments a
            JOIN poll_choices c ON c.id = a.choice_id
            JOIN poll_dates   d ON d.id = c.date_id
            WHERE d.poll_id = ?
        ) ap ON ap.participant_id = p.id
        LEFT JOIN notifications n ON n.poll_id = p.poll_id AND n.participant_id = p.id
        WHERE p.poll_id = ?
        ORDER BY p.name, p.email
    ");
    $stmt->execute([$poll_id, $poll_id]);
    return $stmt->fetchAll();
}

/**
 * Crée ou met à jour la notification d'un·e participant·e avec un statut
 * posé manuellement par le·la manager (action 'confirm' ou 'contest').
 * Si aucune notif n'existe (jamais envoyée par email), on en crée une
 * synthétique avec un token aléatoire inutilisable (sent_at = now).
 */
function manager_set_notification_status(int $poll_id, int $participant_id, string $status, string $reply = ''): bool {
    if (!in_array($status, ['confirmed', 'contested'], true)) return false;
    $pdo = db();
    $now = time();
    $stmt = $pdo->prepare("SELECT id FROM notifications WHERE poll_id = ? AND participant_id = ?");
    $stmt->execute([$poll_id, $participant_id]);
    $row = $stmt->fetch();
    if ($row) {
        $upd = $pdo->prepare(
            "UPDATE notifications SET status = ?, reply = ?, responded_at = ?, responded_by = 'manager'
             WHERE id = ?"
        );
        $upd->execute([$status, $reply, $now, (int)$row['id']]);
        return true;
    }
    // Synthétique : pas d'email, token bidon (jamais distribué). Conserve
    // la contrainte UNIQUE et les jointures du reste de l'UI.
    $fake_token_hash = hash('sha256', 'manual-' . random_bytes(16));
    $ins = $pdo->prepare(
        "INSERT INTO notifications
            (poll_id, participant_id, token_hash, status, reply, sent_at, responded_at, responded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'manager')"
    );
    $ins->execute([$poll_id, $participant_id, $fake_token_hash, $status, $reply, $now, $now]);
    return true;
}

/**
 * Réinitialise une notification : repasse à 'sent' (attente de réponse),
 * efface la réponse précédente. Utile pour annuler un override manager
 * fait par erreur. Pas d'effet si la notif n'existe pas.
 */
function manager_reset_notification_status(int $poll_id, int $participant_id): bool {
    $pdo = db();
    $upd = $pdo->prepare(
        "UPDATE notifications SET status = 'sent', reply = '', responded_at = NULL, responded_by = ''
         WHERE poll_id = ? AND participant_id = ?"
    );
    $upd->execute([$poll_id, $participant_id]);
    return $upd->rowCount() > 0;
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

/**
 * Envoie un email récapitulatif d'astreintes à un·e participant·e
 * avec un lien de confirmation/contestation utilisant le token donné.
 */
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

/**
 * Envoie un email DE TEST à une adresse arbitraire avec des données
 * d'astreintes simulées. Utile pour vérifier le rendu et le bon
 * acheminement chez différents fournisseurs avant un envoi réel.
 */
function send_test_notification_email(array $poll, string $to_email, string $custom_message): void {
    $app_url = rtrim($GLOBALS['CONFIG']['app_url'], '/');
    $name = 'Destinataire de test';
    $subject = '[TEST] Vos astreintes pour « ' . $poll['title'] . ' »';
    $body  = "============ EMAIL DE TEST ============\n";
    $body .= "Ce message simule celui qu'un·e participant·e recevra.\n";
    $body .= "Aucune action n'est enregistrée.\n";
    $body .= "=======================================\n\n";

    $body .= "Bonjour $name,\n\n";
    $body .= "Les astreintes du sondage « {$poll['title']} » viennent d'être posées par l'organisateur.\n\n";
    $body .= "Vos créneaux (exemple) :\n";
    $body .= "  • Lundi 1er juin 2026 — Soirée — Principal·e\n";
    $body .= "  • Mardi 2 juin 2026 — Nuit — Suppléant·e\n";
    $body .= "  • Vendredi 5 juin 2026 — Journée — Suppléant·e\n\n";

    if ($custom_message !== '') {
        $body .= "Message de l'organisateur :\n$custom_message\n\n";
    }
    $body .= "Pour confirmer (ou signaler un problème) :\n";
    $body .= "[Ici serait inséré le lien de confirmation unique]\n\n";
    $body .= "Voir l'ensemble du sondage :\n$app_url/p/{$poll['uuid']}\n\n";
    $body .= "Merci !\n\n";
    $body .= "=======================================\n";
    $body .= "Fin de l'email de test.\n";

    send_mail($to_email, $subject, $body);
}

/**
 * Génère un token frais, invalide la notification précédente s'il y
 * en a une pour ce (poll, participant), persiste la nouvelle. Retourne
 * le token en clair (à inclure dans l'email).
 */
function issue_notification(int $poll_id, int $participant_id): string {
    $token = bin2hex(random_bytes(24));
    $hash  = hash('sha256', $token);
    $pdo = db();
    $pdo->beginTransaction();
    $del = $pdo->prepare("DELETE FROM notifications WHERE poll_id = ? AND participant_id = ?");
    $del->execute([$poll_id, $participant_id]);
    $ins = $pdo->prepare("INSERT INTO notifications (poll_id, participant_id, token_hash, status, sent_at) VALUES (?, ?, ?, 'sent', ?)");
    $ins->execute([$poll_id, $participant_id, $hash, time()]);
    $pdo->commit();
    return $token;
}
