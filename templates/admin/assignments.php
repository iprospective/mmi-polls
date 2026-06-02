<?php
// Astreintes : pour chaque créneau, sélection d'un principal + d'un suppléant
// parmi les participants ayant répondu "oui" ou "peut-être".
// Le libellé des options des <select> est régénéré côté JS à chaque changement
// pour montrer le compte courant (Pₓ / Sᵧ) par personne.

// Compte initial par participant (basé sur les valeurs sélectionnées en base).
$counts = [];
foreach ($participants as $p) {
    $counts[(int)$p['id']] = ['primary' => 0, 'backup' => 0];
}
foreach ($assigns as $cid => $roles) {
    foreach ($roles as $role => $pid) {
        if (!isset($counts[$pid])) $counts[$pid] = ['primary' => 0, 'backup' => 0];
        $counts[$pid][$role]++;
    }
}

// Map id -> nom (fallback sur la partie locale de l'email).
$name_of = [];
foreach ($participants as $p) {
    $name_of[(int)$p['id']] = $p['name'] !== '' ? $p['name'] : explode('@', $p['email'])[0];
}

$total_choices = 0;
foreach ($dates as $d) $total_choices += count($d['choices']);

// Filtre dates passées (toggle ?show_past=1)
$today = date('Y-m-d');
$past_count = 0;
foreach ($dates as $d) if ($d['day'] < $today) $past_count++;
$show_past = !empty($_GET['show_past']);
if (!$show_past && $past_count > 0) {
    $dates = array_values(array_filter($dates, fn($d) => $d['day'] >= $today));
}
$_base = strtok($_SERVER['REQUEST_URI'], '?');
$_qs   = $_GET;
if ($show_past) { unset($_qs['show_past']); } else { $_qs['show_past'] = 1; }
$toggle_url = $_base . ($_qs ? '?' . http_build_query($_qs) : '');
?>

<?php $active = 'assignments'; require __DIR__ . '/_admin_nav.php'; ?>

<?php if (empty($poll['assignments_public'])): ?>
  <div class="card draft-banner">
    <strong>🔒 Astreintes en mode brouillon</strong> — non visibles côté participant.
    Les pictos P/S sur la grille publique, la section « Mes astreintes » et le flux iCal restent masqués.
    Activez la publication depuis l'onglet <a href="/admin/polls/<?= e($poll['uuid']) ?>/settings">Paramètres</a> quand vous êtes prêt.
  </div>
<?php endif; ?>

<p class="muted">
  <?= $total_choices ?> créneaux, <?= count($participants) ?> participants
</p>

<?php if ($past_count > 0): ?>
<p class="past-toggle">
  <span>
    <?php if ($show_past): ?>
      Toutes les dates affichées, y compris <?= $past_count ?> passée<?= $past_count > 1 ? 's' : '' ?>.
    <?php else: ?>
      <?= $past_count ?> date<?= $past_count > 1 ? 's' : '' ?> passée<?= $past_count > 1 ? 's' : '' ?> masquée<?= $past_count > 1 ? 's' : '' ?>.
    <?php endif; ?>
  </span>
  <a href="<?= e($toggle_url) ?>"><?= $show_past ? 'Masquer les dates passées' : 'Tout afficher' ?></a>
</p>
<?php endif; ?>

<form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/assignments" id="assignments-form">
  <?= csrf_field() ?>

  <div class="grid-wrap">
  <table class="vote-grid assignments-grid">
    <thead>
      <tr>
        <th class="date-col">Date</th>
        <th class="slot-col">Créneau</th>
        <th>Récap</th>
        <th>Principal·e</th>
        <th>Suppléant·e</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($dates as $d):
      $rows = count($d['choices']);
      $first = true;
      foreach ($d['choices'] as $c):
        // Candidats : participants ayant voté "yes" ou "maybe" pour ce créneau.
        $cid = (int)$c['id'];
        $yes_ids = [];
        $maybe_ids = [];
        $n = ['yes' => 0, 'no' => 0, 'maybe' => 0];
        foreach ($participants as $p) {
            $v = $votes[$p['id']][$cid] ?? null;
            if ($v === 'yes')   { $yes_ids[]   = (int)$p['id']; $n['yes']++; }
            elseif ($v === 'maybe') { $maybe_ids[] = (int)$p['id']; $n['maybe']++; }
            elseif ($v === 'no') $n['no']++;
        }
        $sel_primary = $assigns[$cid]['primary'] ?? 0;
        $sel_backup  = $assigns[$cid]['backup']  ?? 0;
    ?>
      <tr<?= $first ? ' class="day-first"' : '' ?>>
        <?php if ($first): ?>
          <th class="date-cell" rowspan="<?= $rows ?>">
            <?= e(fmt_day($d['day'])) ?><br>
            <span class="muted small"><?= e($d['day']) ?></span>
          </th>
        <?php endif; $first = false; ?>
        <td class="slot-cell"><?= e($c['label']) ?></td>
        <td class="summary-cell small">
          <span class="v-yes"><?= $n['yes'] ?>✓</span>
          <span class="v-maybe"><?= $n['maybe'] ?>?</span>
          <span class="v-no"><?= $n['no'] ?>✗</span>
        </td>
        <?php foreach (['primary' => $sel_primary, 'backup' => $sel_backup] as $role => $sel):
          $role_label = $role === 'primary' ? 'Principal·e' : 'Suppléant·e';
        ?>
          <td data-role="<?= e($role_label) ?>">
            <select class="assign-select" data-role="<?= $role ?>"
                    data-prev="<?= (int)$sel ?>"
                    name="assignments[<?= $cid ?>][<?= $role ?>]">
              <option value="0" data-pid="0">—</option>
              <?php if (!empty($yes_ids)): ?>
                <optgroup label="A voté Oui">
                  <?php foreach ($yes_ids as $pid):
                      $n_p = $counts[$pid]['primary'];
                      $n_s = $counts[$pid]['backup'];
                  ?>
                    <option value="<?= $pid ?>"
                            data-pid="<?= $pid ?>"
                            data-name="<?= e($name_of[$pid]) ?>"
                            <?= $sel === $pid ? 'selected' : '' ?>>
                      <?= e($name_of[$pid]) ?> (<?= $n_p ?>P / <?= $n_s ?>S)
                    </option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endif; ?>
              <?php if (!empty($maybe_ids)): ?>
                <optgroup label="A voté Peut-être">
                  <?php foreach ($maybe_ids as $pid):
                      $n_p = $counts[$pid]['primary'];
                      $n_s = $counts[$pid]['backup'];
                  ?>
                    <option value="<?= $pid ?>"
                            data-pid="<?= $pid ?>"
                            data-name="<?= e($name_of[$pid]) ?>"
                            <?= $sel === $pid ? 'selected' : '' ?>>
                      <?= e($name_of[$pid]) ?> (<?= $n_p ?>P / <?= $n_s ?>S)
                    </option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endif; ?>
              <?php if (empty($yes_ids) && empty($maybe_ids)): ?>
                <option disabled>aucun candidat dispo</option>
              <?php endif; ?>
            </select>
          </td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; endforeach; ?>
    </tbody>
  </table>
  </div>

  <div class="assignments-bar">
    <button type="submit">Enregistrer les astreintes</button>
    <a href="/admin/polls/<?= e($poll['uuid']) ?>" class="link">Retour au sondage</a>
    <div class="muted small" id="assignments-totals"></div>
  </div>
</form>

<div class="auto-fill-wrap">

<form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/assignments/auto-fill"
      class="card"
      onsubmit="return confirm('Remplir automatiquement tous les créneaux encore vides ? Les assignations existantes ne seront pas touchées.');">
  <?= csrf_field() ?>
  <h3 style="margin-top:0;">Remplissage automatique</h3>
  <p class="muted small">
    <strong>Tous les principaux d'abord, puis tous les suppléants.</strong>
    Priorité de libellé&nbsp;: Nuit &gt; Soirée &gt; Journée. Au sein de chaque créneau,
    les candidat·e·s (Oui en priorité, sinon Peut-être) sont classé·e·s par
    pourcentage d'usage&nbsp;:
    <span class="tier-badge tier-0">&lt;50&nbsp;% prioritaire</span>,
    <span class="tier-badge tier-1">50–75&nbsp;%</span>,
    <span class="tier-badge tier-2">75–90&nbsp;%</span>,
    <span class="tier-badge tier-3">≥90&nbsp;% dernier recours</span>.
    Au sein des prioritaires, les personnes qui ont coché peu de Oui passent en
    premier (sinon elles seraient noyées). N'écrase rien de ce qui est déjà posé.
  </p>
  <button type="submit">Remplir auto les créneaux vides</button>
</form>

<form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/assignments/clear"
      class="card"
      onsubmit="return confirm('Supprimer TOUTES les astreintes posées sur ce sondage ? Action irréversible.');">
  <?= csrf_field() ?>
  <h3 style="margin-top:0;">Repartir de zéro</h3>
  <p class="muted small">Supprime toutes les astreintes (principales et suppléantes)
    posées sur ce sondage. Utile avant un remplissage auto si tu veux que l'algo
    décide de tout de bout en bout.</p>
  <button type="submit" class="danger">Vider toutes les astreintes</button>
</form>

</div>

<?php if (!empty($open_swaps)): ?>
<h2>Demandes de remplacement en cours (<?= count($open_swaps) ?>)</h2>
<p class="muted small">
  Les participant·e·s ont sollicité d'autres candidat·e·s pour reprendre leur créneau.
  Premier·ère qui clique « J'accepte » dans son email gagne, et les astreintes se mettent à jour automatiquement.
  Vous pouvez annuler une demande (utile si vous avez d'autres plans pour ce créneau).
</p>
<div class="grid-wrap">
<table class="dates-table notif-status">
  <thead>
    <tr>
      <th>Créneau</th>
      <th>Demandeur·euse</th>
      <th>Sollicité·e·s</th>
      <th>Réponses</th>
      <th>Depuis</th>
      <th class="no-sort">Actions</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($open_swaps as $s):
      $r_name = $s['requester_name'] !== '' ? $s['requester_name'] : explode('@', $s['requester_email'])[0];
      $awaiting = max(0, (int)$s['n_targets'] - (int)$s['n_declines']);
    ?>
      <tr>
        <td>
          <strong><?= e(fmt_day($s['day'])) ?></strong>
          <span class="muted small"><?= e($s['day']) ?></span><br>
          <?= e($s['slot_label']) ?>
          <span class="role-badge role-<?= e($s['role']) ?>"><?= $s['role'] === 'primary' ? 'P' : 'S' ?></span>
        </td>
        <td><strong><?= e($r_name) ?></strong><br><span class="muted small"><?= e($s['requester_email']) ?></span></td>
        <td class="num"><?= (int)$s['n_targets'] ?></td>
        <td class="small">
          <span class="muted"><?= (int)$s['n_declines'] ?> décliné</span> ·
          <strong><?= $awaiting ?></strong> en attente
        </td>
        <td class="small"><?= e(date('d/m/Y H:i', (int)$s['created_at'])) ?></td>
        <td class="actions-cell">
          <form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/swap/<?= (int)$s['id'] ?>/cancel" class="inline"
                onsubmit="return confirm('Annuler cette demande de remplacement ? Les destinataires seront prévenu·e·s.');">
            <?= csrf_field() ?>
            <button type="submit" class="link danger">annuler</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<h2>Notifications &amp; confirmations</h2>

<?php if (!$poll['contact_email']): ?>
<p class="muted small">
  ⚠️ Aucune adresse de contact configurée pour ce sondage.
  Les signalements de problèmes seront enregistrés en base mais pas envoyés par email.
  <a href="/admin/polls/<?= e($poll['uuid']) ?>/settings">Configurer dans Paramètres →</a>
</p>
<?php else: ?>
<p class="muted small">
  Signalements envoyés à <code><?= e($poll['contact_email']) ?></code>
  (<a href="/admin/polls/<?= e($poll['uuid']) ?>/settings">modifier</a>).
</p>
<?php endif; ?>

<form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/assignments/notify" class="card">
  <?= csrf_field() ?>
  <h3 style="margin-top:0;">Envoyer une notification</h3>

  <fieldset class="target-radio">
    <legend class="sr-only">Destinataire</legend>
    <label>
      <input type="radio" name="target" value="all" checked>
      Tous les participants ayant au moins un créneau d'astreinte
      (<?= count($assigned_participants) ?>)
    </label>
    <label>
      <input type="radio" name="target" value="one">
      Une personne en particulier :
      <select name="participant_id">
        <option value="0">—</option>
        <?php foreach ($participants as $p):
          $name = $p['name'] !== '' ? $p['name'] : explode('@', $p['email'])[0];
        ?>
          <option value="<?= (int)$p['id'] ?>"><?= e($name) ?> &lt;<?= e($p['email']) ?>&gt;</option>
        <?php endforeach; ?>
      </select>
    </label>
  </fieldset>

  <label>Message personnalisé (facultatif)
    <textarea name="message" id="notif-message" rows="3" placeholder="Ce qui sera ajouté à l'email avant la liste des astreintes…"><?= e((string)($GLOBALS['CONFIG']['notifications']['default_message'] ?? '')) ?></textarea>
    <?php if (!empty($GLOBALS['CONFIG']['notifications']['default_message'])): ?>
      <small class="muted">Pré-rempli avec le message par défaut de la config. Éditable ou videable au cas par cas.</small>
    <?php endif; ?>
  </label>

  <p class="muted small">Chaque destinataire reçoit la liste de ses propres astreintes
     et un lien unique pour confirmer ou signaler un problème.
     Renvoyer une notification à une personne invalide le lien précédent.</p>

  <hr style="border: none; border-top: 1px dashed var(--border-strong); margin: 1rem 0;">

  <h4 style="margin: 0.5rem 0;">Envoi de test (vérification du rendu)</h4>
  <p class="muted small">Envoie un email avec des créneaux d'exemple (et le message personnalisé ci-dessus) à l'adresse de votre choix. Pratique pour vérifier le rendu chez différents fournisseurs (Gmail, Outlook, ProtonMail…) avant l'envoi réel. <strong>Aucune notification persistée, aucun token généré.</strong></p>
  <div class="row">
    <input type="email" name="test_email" placeholder="votre.email@exemple.com">
    <button type="submit"
            formaction="/admin/polls/<?= e($poll['uuid']) ?>/assignments/notify-test"
            formnovalidate>📨 Envoyer un test</button>
  </div>

  <hr style="border: none; border-top: 1px dashed var(--border-strong); margin: 1rem 0;">

  <button type="submit"
          onclick="return confirm('Envoyer la notification d\'astreintes aux destinataires sélectionnés ?');">
    Envoyer aux destinataires sélectionnés
  </button>
</form>

<?php if ($notifs): ?>
  <h3>Statut par personne assignée</h3>
  <p class="muted small">
    Pour quelqu'un·e qui n'a pas internet ou que vous avez eu en direct,
    les boutons <strong>✓ Confirmer</strong> / <strong>✗ Signaler</strong> permettent de
    poser la réponse à sa place. <strong>↺ Réinitialiser</strong> rebascule en attente.
  </p>
  <div class="grid-wrap">
  <table class="dates-table notif-status">
    <thead>
      <tr>
        <th>Participant·e</th>
        <th>Envoyé le</th>
        <th>Statut</th>
        <th>Réponse</th>
        <th class="no-sort">Override manager</th>
      </tr>
    </thead>
    <tbody>
      <?php
        $statuses = [
          'sent'      => ['label' => 'Envoyé',          'cls' => 'status-sent'],
          'confirmed' => ['label' => 'Confirmé',        'cls' => 'status-confirmed-row'],
          'contested' => ['label' => 'Contesté',        'cls' => 'status-contested-row'],
        ];
      foreach ($notifs as $n):
        $name   = $n['name'] !== '' ? $n['name'] : explode('@', $n['email'])[0];
        $a_ts   = (int)($n['assignments_updated_at'] ?? 0);
        $has_notif = !empty($n['notif_id']);
        $status  = $has_notif ? (string)$n['notif_status'] : 'never';
        $sent_at = (int)($n['notif_sent_at'] ?? 0);
        $resp_at = (int)($n['notif_responded_at'] ?? 0);
        $resp_by = (string)($n['notif_responded_by'] ?? '');
        $reply   = (string)($n['notif_reply'] ?? '');
        $stale   = $has_notif && $a_ts > 0 && $a_ts > $sent_at;
        $row_cls = $has_notif ? ($statuses[$status]['cls'] ?? '') : 'status-never-row';
      ?>
        <tr class="<?= e($row_cls) ?><?= $stale ? ' notif-stale-row' : '' ?>">
          <td><strong><?= e($name) ?></strong><br><span class="muted small"><?= e($n['email']) ?></span></td>
          <td class="small">
            <?php if ($has_notif): ?>
              <?= e(date('d/m/Y H:i', $sent_at)) ?>
            <?php else: ?>
              <span class="muted">jamais envoyé</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($has_notif): ?>
              <span class="notif-badge <?= e($statuses[$status]['cls']) ?>">
                <?= e($statuses[$status]['label']) ?>
              </span>
              <?php if ($resp_by === 'manager'): ?>
                <br><span class="muted small">posé par le manager</span>
              <?php endif; ?>
              <?php if ($resp_at): ?>
                <br><span class="muted small"><?= e(date('d/m/Y H:i', $resp_at)) ?></span>
              <?php endif; ?>
            <?php else: ?>
              <span class="notif-badge status-never">Non notifié·e</span>
            <?php endif; ?>
            <?php if ($stale): ?>
              <br><span class="notif-badge notif-stale"
                       title="Astreintes modifiées le <?= e(date('d/m/Y H:i', $a_ts)) ?>, après l'envoi de cette notification">
                ⚠️ À renotifier
              </span>
            <?php endif; ?>
          </td>
          <td><?php if ($reply): ?><blockquote class="contest-reply"><?= nl2br(e($reply)) ?></blockquote><?php endif; ?></td>
          <td class="actions-cell">
            <form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/notifications/<?= (int)$n['id'] ?>/override" class="override-actions">
              <?= csrf_field() ?>
              <button type="submit" name="action" value="confirm"
                      <?= $status === 'confirmed' ? 'disabled' : '' ?>
                      title="Marquer comme confirmé (hors-mail)">✓ Confirmer</button>
              <button type="submit" name="action" value="contest" class="danger"
                      onclick="var r = prompt('Note pour ce signalement (facultatif) :', ''); if (r === null) return false; this.form.reply.value = r; return true;"
                      <?= $status === 'contested' ? 'disabled' : '' ?>
                      title="Marquer comme contesté (hors-mail)">✗ Signaler</button>
              <input type="hidden" name="reply" value="">
              <?php if ($has_notif && $status !== 'sent'): ?>
                <button type="submit" name="action" value="reset" class="link"
                        title="Réinitialiser : repasse en attente de réponse">↺ Réinitialiser</button>
              <?php endif; ?>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
