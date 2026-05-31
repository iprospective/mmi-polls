<?php
// Service Activity Log : helper d'instrumentation + lecture.
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

/**
 * Enregistre une action dans le journal d'activité d'un sondage.
 *
 * @param int    $poll_id
 * @param string $action  verbe court genre 'vote_save', 'assign_save'
 * @param array  $opts    clés possibles :
 *   - target      (string)  : description humaine de l'objet impacté
 *   - payload     (array)   : sérialisé en JSON, pour détails
 *   - actor_type  (string)  : override sinon auto-détecté (admin/manager/participant/system)
 *   - actor_id    (int|null): override
 *   - actor_label (string)  : override (sinon construit depuis la session)
 */
function log_activity(int $poll_id, string $action, array $opts = []): void {
    $actor_type  = $opts['actor_type']  ?? null;
    $actor_id    = $opts['actor_id']    ?? null;
    $actor_label = $opts['actor_label'] ?? null;
    if ($actor_type === null) {
        if (is_admin()) {
            $actor_type  = 'admin';
            $actor_id    = null;
            $actor_label = 'Admin global';
        } elseif (is_manager()) {
            $actor_type  = 'manager';
            $actor_id    = current_manager_id();
            $name = trim((string)($_SESSION['manager_name'] ?? ''));
            $actor_label = $name !== '' ? $name : (string)($_SESSION['manager_email'] ?? '');
        } else {
            $actor_type  = 'system';
            $actor_label = 'Système';
        }
    }
    $target  = (string)($opts['target']  ?? '');
    $payload = isset($opts['payload']) ? json_encode($opts['payload'], JSON_UNESCAPED_UNICODE) : '';

    $stmt = db()->prepare("
        INSERT INTO activity_log
            (poll_id, actor_type, actor_id, actor_label, action, target, payload, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$poll_id, $actor_type, $actor_id, $actor_label, $action, $target, $payload, time()]);
}

function list_activity(int $poll_id, int $limit = 200): array {
    $stmt = db()->prepare("
        SELECT * FROM activity_log
        WHERE poll_id = ?
        ORDER BY created_at DESC, id DESC
        LIMIT ?
    ");
    $stmt->execute([$poll_id, $limit]);
    return $stmt->fetchAll();
}

/**
 * Libellé humain d'une action pour l'affichage.
 */
function action_label(string $action): string {
    static $labels = [
        'vote_save'              => 'a saisi ses disponibilités',
        'vote_save_admin'        => 'a édité les disponibilités de',
        'participant_create'     => 'a créé un participant',
        'participant_delete'     => 'a supprimé un participant',
        'participant_hide'       => 'a masqué un participant en vue publique',
        'participant_show'       => 'a réaffiché un participant en vue publique',
        'participant_remind'     => 'a relancé un non-répondant',
        'participant_remind_all' => 'a relancé tous les non-répondants',
        'assign_save'            => 'a enregistré les astreintes',
        'assign_autofill'        => 'a lancé le remplissage automatique',
        'assign_clear'           => 'a vidé toutes les astreintes',
        'notification_send'      => 'a envoyé les notifications d\'astreintes',
        'contact_email_update'   => 'a modifié l\'email de contact',
        'settings_update'        => 'a mis à jour les paramètres du sondage',
        'date_add'               => 'a ajouté une date',
        'date_add_bulk'          => 'a ajouté une plage de dates',
        'date_delete'            => 'a supprimé une date',
        'choice_add'             => 'a ajouté un créneau',
        'choice_delete'          => 'a supprimé un créneau',
        'poll_manager_add'       => 'a ajouté un manager au sondage',
        'poll_manager_remove'    => 'a retiré un manager du sondage',
    ];
    return $labels[$action] ?? $action;
}
