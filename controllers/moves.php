<?php
// Controller : déplacement/échange d'astreintes initié par le manager
// depuis le calendrier (drag'n'drop).
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/mailer.php';
require_once __DIR__ . '/../services/polls.php';
require_once __DIR__ . '/../services/managers.php';
require_once __DIR__ . '/../services/move_requests.php';
require_once __DIR__ . '/../services/activity_log.php';

/**
 * POST appelé par le calendrier en AJAX après confirmation du drop.
 * Retourne du JSON { ok, request_id?, error? } pour rester réactif côté UI.
 */
function route_admin_create_move_request(string $uuid): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    header('Content-Type: application/json; charset=UTF-8');

    $src_cid = (int)($_POST['src_cid'] ?? 0);
    $dst_cid = (int)($_POST['dst_cid'] ?? 0);
    $src_role = (string)($_POST['src_role'] ?? '');
    $dst_role = (string)($_POST['dst_role'] ?? '');
    $message  = trim((string)($_POST['message'] ?? ''));

    if ($src_cid <= 0 || $dst_cid <= 0
        || !in_array($src_role, ['primary', 'backup'], true)
        || !in_array($dst_role, ['primary', 'backup'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad_input']);
        exit;
    }

    $label = manager_display_label();
    try {
        $req_id = create_move_request($poll, $src_cid, $src_role, $dst_cid, $dst_role, $message, $label);
    } catch (RuntimeException | InvalidArgumentException $e) {
        // Erreur métier attendue (validation, état incohérent). Log warn,
        // pas de notif admin (l'utilisateur·rice voit déjà le message).
        log_warn('create_move_request rejected', log_throwable($e) + [
            'src_cid' => $src_cid, 'src_role' => $src_role,
            'dst_cid' => $dst_cid, 'dst_role' => $dst_role,
        ]);
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    } catch (Throwable $e) {
        // Erreur inattendue (PDO, etc.) : prévenir l'admin.
        notify_admin_error($e, 'create_move_request crashed', [
            'src_cid' => $src_cid, 'src_role' => $src_role,
            'dst_cid' => $dst_cid, 'dst_role' => $dst_role,
        ]);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Erreur interne. L\'admin a été averti·e.']);
        exit;
    }
    log_activity((int)$poll['id'], 'move_request', [
        'target'  => 'request #' . $req_id,
        'payload' => ['src_cid' => $src_cid, 'src_role' => $src_role, 'dst_cid' => $dst_cid, 'dst_role' => $dst_role],
    ]);
    echo json_encode(['ok' => true, 'request_id' => $req_id]);
    exit;
}

/**
 * Annulation par le manager (depuis l'admin).
 */
function route_admin_cancel_move_request(string $uuid, string $req_id): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    $rid = (int)$req_id;
    $label = manager_display_label();
    if (cancel_move_request($rid, $label)) {
        log_activity((int)$poll['id'], 'move_cancel', ['target' => 'request #' . $rid]);
        flash_set('ok', 'Demande de déplacement annulée.');
    } else {
        flash_set('err', 'Cette demande n\'est plus annulable.');
    }
    redirect('/admin/polls/' . $uuid . '/calendar');
}

/**
 * Landing destinataire — pas d'auth, le token suffit.
 */
function route_move_respond_get(string $token): void {
    $resp = find_move_response_by_token($token);
    if (!$resp) not_found();
    $req = load_move_request_full((int)$resp['request_id']);
    if (!$req) not_found();
    render('move/respond', [
        'page_title' => 'Demande d\'échange d\'astreinte — ' . $req['poll_title'],
        'req'   => $req,
        'resp'  => $resp,
        'token' => $token,
    ]);
}

function route_move_respond_post(string $token): void {
    $resp = find_move_response_by_token($token);
    if (!$resp) not_found();
    $action = (string)($_POST['action'] ?? '');
    $redir  = '/move/' . urlencode($token);
    if (!in_array($action, ['accept', 'decline'], true)) redirect($redir);

    $res = respond_to_move_request($token, $action);
    if (empty($res['ok'])) {
        $messages = [
            'not_found'    => 'Lien invalide.',
            'bad_response' => 'Action invalide.',
        ];
        flash_set('err', $messages[$res['reason'] ?? ''] ?? 'Erreur.');
        redirect($redir);
    }
    $status = $res['status'] ?? '';
    $msgs = [
        'already_responded' => 'Vous aviez déjà répondu à cette demande.',
        'waiting_other'     => 'Merci ! On attend la réponse de l\'autre personne avant d\'appliquer.',
        'applied'           => 'Échange appliqué. Votre planning est à jour.',
        'declined'          => 'Refus enregistré. La demande est clôturée.',
        'expired'           => 'Les astreintes ont changé entre temps, la demande n\'est plus valable.',
    ];
    if (in_array($status, ['applied', 'declined', 'expired'], true)) {
        log_activity((int)$resp['request_id'], 'move_close', [
            'target'  => $action,
            'payload' => ['status' => $status],
        ]);
    }
    flash_set($status === 'expired' ? 'err' : 'ok', $msgs[$status] ?? 'Réponse enregistrée.');
    redirect($redir);
}

/**
 * Renvoie un libellé pour identifier l'initiateur dans les emails et logs.
 */
function manager_display_label(): string {
    if (is_admin()) return 'l\'organisateur·rice (admin)';
    $mid = current_manager_id();
    if ($mid === null) return 'l\'organisateur·rice';
    $m = find_manager_by_id($mid);
    if (!$m) return 'l\'organisateur·rice';
    return $m['name'] !== '' ? $m['name'] : $m['email'];
}
