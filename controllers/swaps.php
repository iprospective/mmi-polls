<?php
// Controller : demandes de remplacement d'astreinte entre participant·e·s.
// - /p/UUID/swap/new : formulaire (auth participant)
// - /p/UUID/swap/REQ/cancel : annulation par le requester
// - /swap/TOKEN : landing destinataire (accepte/décline)
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/mailer.php';
require_once __DIR__ . '/../services/polls.php';
require_once __DIR__ . '/../services/assignments.php';
require_once __DIR__ . '/../services/swaps.php';
require_once __DIR__ . '/../services/activity_log.php';

/**
 * Page de composition d'une demande de remplacement.
 * Query: ?cid=<choice_id>&role=<primary|backup>
 */
function route_poll_swap_new_form(string $uuid): void {
    $poll = find_poll($uuid);
    $auth = participant_session($uuid);
    if (!$auth) redirect('/p/' . $uuid . '/login');
    if (poll_is_closed($poll)) {
        flash_set('err', 'Sondage clos : les demandes de remplacement sont désactivées.');
        redirect('/p/' . $uuid . '/me');
    }
    if (empty($poll['assignments_public'])) {
        flash_set('err', 'Les astreintes ne sont pas encore publiées.');
        redirect('/p/' . $uuid . '/me');
    }
    $pid  = (int)$auth['participant_id'];
    $cid  = (int)($_GET['cid'] ?? 0);
    $role = (string)($_GET['role'] ?? '');
    if (!in_array($role, ['primary', 'backup'], true) || $cid <= 0) {
        flash_set('err', 'Créneau invalide.');
        redirect('/p/' . $uuid . '/me');
    }

    $pdo = db();
    // Vérifie que ce participant tient bien CETTE astreinte.
    $own = $pdo->prepare("
        SELECT a.participant_id, d.day, c.label
        FROM assignments a
        JOIN poll_choices c ON c.id = a.choice_id
        JOIN poll_dates   d ON d.id = c.date_id
        WHERE a.choice_id = ? AND a.role = ? AND d.poll_id = ?
    ");
    $own->execute([$cid, $role, (int)$poll['id']]);
    $row = $own->fetch();
    if (!$row || (int)$row['participant_id'] !== $pid) {
        flash_set('err', 'Cette astreinte n\'est pas (ou plus) la vôtre.');
        redirect('/p/' . $uuid . '/me');
    }

    // Déjà une demande ouverte sur ce (cid, role) ?
    $dup = $pdo->prepare("SELECT id FROM swap_requests WHERE poll_id = ? AND choice_id = ? AND role = ? AND status = 'open'");
    $dup->execute([(int)$poll['id'], $cid, $role]);
    if ($dup->fetch()) {
        flash_set('err', 'Une demande est déjà en cours pour ce créneau.');
        redirect('/p/' . $uuid . '/me');
    }

    $candidate_pids = swap_candidate_pids((int)$poll['id'], $cid, $pid);
    if (!$candidate_pids) {
        flash_set('err', 'Aucun·e candidat·e n\'avait répondu Oui ou Peut-être à ce créneau. Contactez votre organisateur·rice directement.');
        redirect('/p/' . $uuid . '/me');
    }

    $place = implode(',', array_fill(0, count($candidate_pids), '?'));
    $cstmt = $pdo->prepare("
        SELECT p.id, p.name, p.email,
               (SELECT value FROM votes v WHERE v.participant_id = p.id AND v.choice_id = ?) AS my_vote
        FROM participants p
        WHERE p.id IN ($place)
        ORDER BY p.name, p.email
    ");
    $cstmt->execute(array_merge([$cid], $candidate_pids));
    $candidates = $cstmt->fetchAll();

    render('swap/new', [
        'page_title'  => 'Demander un remplacement — ' . $poll['title'],
        'poll'        => $poll,
        'cid'         => $cid,
        'role'        => $role,
        'slot_day'    => $row['day'],
        'slot_label'  => $row['label'],
        'candidates'  => $candidates,
    ]);
}

function route_poll_swap_new_submit(string $uuid): void {
    $poll = find_poll($uuid);
    $auth = participant_session($uuid);
    if (!$auth) redirect('/p/' . $uuid . '/login');
    if (poll_is_closed($poll)) {
        flash_set('err', 'Sondage clos.');
        redirect('/p/' . $uuid . '/me');
    }
    $pid     = (int)$auth['participant_id'];
    $cid     = (int)($_POST['cid']  ?? 0);
    $role    = (string)($_POST['role'] ?? '');
    $message = trim((string)($_POST['message'] ?? ''));
    $targets = $_POST['targets'] ?? [];
    if (!is_array($targets)) $targets = [];

    try {
        $req_id = issue_swap_request($poll, $pid, $cid, $role, array_map('intval', $targets), $message);
    } catch (RuntimeException | InvalidArgumentException $e) {
        log_warn('issue_swap_request rejected', log_throwable($e) + ['cid' => $cid, 'role' => $role]);
        flash_set('err', 'Demande impossible : ' . $e->getMessage());
        redirect('/p/' . $uuid . '/me');
    } catch (Throwable $e) {
        notify_admin_error($e, 'issue_swap_request crashed', ['pid' => $pid, 'cid' => $cid, 'role' => $role]);
        flash_set('err', 'Erreur interne. L\'admin a été averti·e.');
        redirect('/p/' . $uuid . '/me');
    }

    log_activity((int)$poll['id'], 'swap_request', [
        'actor_type'  => 'participant',
        'actor_id'    => $pid,
        'actor_label' => $auth['email'],
        'target'      => 'request #' . $req_id,
        'payload'     => ['n_targets' => count($targets), 'cid' => $cid, 'role' => $role],
    ]);

    flash_set('ok', 'Demande envoyée à ' . count($targets) . ' personne(s). Le premier·ère qui clique reprend votre astreinte.');
    redirect('/p/' . $uuid . '/me');
}

/**
 * Annulation par le requester (auth participant requise).
 */
function route_poll_swap_cancel(string $uuid, string $req_id): void {
    $poll = find_poll($uuid);
    $auth = participant_session($uuid);
    if (!$auth) redirect('/p/' . $uuid . '/login');
    $pid = (int)$auth['participant_id'];
    $rid = (int)$req_id;

    $req = swap_request_full($rid);
    if (!$req || (int)$req['poll_id'] !== (int)$poll['id']) not_found();
    if ((int)$req['requester_pid'] !== $pid) {
        http_response_code(403);
        exit('403 — Cette demande ne vous appartient pas.');
    }
    $r_name = $req['requester_name'] !== '' ? $req['requester_name'] : explode('@', $req['requester_email'])[0];
    if (cancel_swap_request($rid, $r_name)) {
        log_activity((int)$poll['id'], 'swap_cancel', [
            'actor_type'  => 'participant',
            'actor_id'    => $pid,
            'actor_label' => $auth['email'],
            'target'      => 'request #' . $rid,
        ]);
        flash_set('ok', 'Demande annulée. Les destinataires sont prévenu·e·s.');
    } else {
        flash_set('err', 'Cette demande n\'est plus annulable.');
    }
    redirect('/p/' . $uuid . '/me');
}

/**
 * Annulation par un manager (admin override) depuis la page Astreintes.
 */
function route_admin_swap_cancel(string $uuid, string $req_id): void {
    $poll = find_poll($uuid);
    require_poll_access($poll);
    $rid = (int)$req_id;
    $req = swap_request_full($rid);
    if (!$req || (int)$req['poll_id'] !== (int)$poll['id']) not_found();
    $label = is_admin() ? 'l\'administrateur·rice' : 'un·e organisateur·rice';
    if (cancel_swap_request($rid, $label)) {
        log_activity((int)$poll['id'], 'swap_cancel_admin', [
            'target' => 'request #' . $rid,
        ]);
        flash_set('ok', 'Demande annulée.');
    } else {
        flash_set('err', 'Cette demande n\'est plus annulable.');
    }
    redirect('/admin/polls/' . $uuid . '/assignments');
}

/**
 * Landing destinataire — pas d'auth, le token suffit.
 */
function route_swap_respond_get(string $token): void {
    $tgt = find_swap_target_by_token($token);
    if (!$tgt) not_found();
    $req = swap_request_full((int)$tgt['request_id']);
    if (!$req) not_found();
    render('swap/respond', [
        'page_title' => 'Reprendre une astreinte — ' . $req['poll_title'],
        'req'        => $req,
        'target'     => $tgt,
        'token'      => $token,
    ]);
}

function route_swap_respond_post(string $token): void {
    $tgt = find_swap_target_by_token($token);
    if (!$tgt) not_found();
    $req = swap_request_full((int)$tgt['request_id']);
    if (!$req) not_found();

    $action = (string)($_POST['action'] ?? '');
    $redir  = '/swap/' . urlencode($token);

    if ($action === 'decline') {
        decline_swap_request($token);
        flash_set('ok', 'Décliné. Merci d\'avoir pris le temps de répondre.');
        redirect($redir);
    }

    if ($action !== 'accept') {
        redirect($redir);
    }

    $res = accept_swap_request($token);
    if (empty($res['ok'])) {
        $reason = $res['reason'] ?? 'unknown';
        $messages = [
            'taken'     => 'Trop tard : quelqu\'un·e a déjà accepté cette demande.',
            'cancelled' => 'Cette demande a été annulée par le·la demandeur·euse.',
            'expired'   => 'L\'astreinte d\'origine a été modifiée par l\'organisateur·rice, la demande n\'est plus valide.',
            'race'      => 'Trop tard : quelqu\'un·e a accepté au même moment.',
            'not_found' => 'Lien invalide ou expiré.',
        ];
        flash_set('err', $messages[$reason] ?? 'Impossible d\'accepter (' . $reason . ').');
        redirect($redir);
    }

    // Charge contexte post-acceptation pour emails + log.
    $req_after = swap_request_full((int)$tgt['request_id']);
    $accepter  = swap_load_participant((int)$tgt['participant_id']);
    if ($req_after && $accepter) {
        try { send_swap_accepted_email_to_requester($req_after, $accepter); }
        catch (Throwable $e) { mail_log($req_after['requester_email'], '[swap notify req] ' . $e->getMessage(), ''); }
        try { send_swap_accepted_email_to_accepter($req_after, $accepter, !empty($res['freed_other_role'])); }
        catch (Throwable $e) { mail_log($accepter['email'], '[swap notify acc] ' . $e->getMessage(), ''); }
        try { send_swap_manager_summary($req_after, $accepter, $req_after['poll_contact'] ?: null); }
        catch (Throwable $e) { /* silent */ }

        // Prévient les autres targets non-répondants.
        $acc_label = $accepter['name'] !== '' ? $accepter['name'] : explode('@', $accepter['email'])[0];
        foreach ($req_after['targets'] as $t) {
            if ((int)$t['id'] === (int)$tgt['id']) continue;
            if ($t['response'] !== null) continue;
            try { send_swap_too_late_email($req_after, $t, $acc_label); }
            catch (Throwable $e) { /* silent */ }
        }
    }

    log_activity((int)$req['poll_id'], 'swap_accept', [
        'actor_type'  => 'participant',
        'actor_id'    => (int)$tgt['participant_id'],
        'actor_label' => $accepter['email'] ?? '',
        'target'      => 'request #' . (int)$tgt['request_id'],
    ]);

    flash_set('ok', 'C\'est noté ! Vous reprenez cette astreinte. ' . ($req_after['requester_name'] ?? 'Le·la demandeur·euse') . ' est prévenu·e.');
    redirect($redir);
}
