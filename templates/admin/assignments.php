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
?>

<?php $active = 'assignments'; require __DIR__ . '/_admin_nav.php'; ?>

<p class="muted">
  <?= $total_choices ?> créneaux, <?= count($participants) ?> participants
</p>

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

<h2>Notifications &amp; confirmations</h2>

<form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/contact-email" class="card">
  <?= csrf_field() ?>
  <label>Email de contact (pour recevoir les signalements de problèmes)
    <input type="email" name="contact_email"
           value="<?= e($poll['contact_email']) ?>"
           placeholder="ex. moi@exemple.com">
  </label>
  <button type="submit">Enregistrer</button>
</form>

<form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/assignments/notify" class="card"
      onsubmit="return confirm('Envoyer la notification d\'astreintes aux destinataires sélectionnés ?');">
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
    <textarea name="message" rows="3" placeholder="Ce qui sera ajouté au début de l'email avant la liste des astreintes…"></textarea>
  </label>

  <p class="muted small">Chaque destinataire reçoit la liste de ses propres astreintes
     et un lien unique pour confirmer ou signaler un problème.
     Renvoyer une notification à une personne invalide le lien précédent.</p>
  <button type="submit">Envoyer</button>
</form>

<?php if ($notifs): ?>
  <h3>État des envois</h3>
  <table class="dates-table notif-status">
    <thead>
      <tr>
        <th>Participant</th>
        <th>Envoyé le</th>
        <th>Statut</th>
        <th>Réponse</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($notifs as $n):
        $statuses = [
          'sent'      => ['label' => 'Envoyé',    'cls' => 'status-sent'],
          'confirmed' => ['label' => 'Confirmé',  'cls' => 'status-confirmed-row'],
          'contested' => ['label' => 'Contesté',  'cls' => 'status-contested-row'],
        ];
        $s = $statuses[$n['status']] ?? $statuses['sent'];
        $name = $n['name'] !== '' ? $n['name'] : explode('@', $n['email'])[0];
      ?>
        <tr class="<?= e($s['cls']) ?>">
          <td><strong><?= e($name) ?></strong><br><span class="muted small"><?= e($n['email']) ?></span></td>
          <td class="small"><?= e(date('d/m/Y H:i', (int)$n['sent_at'])) ?></td>
          <td><span class="notif-badge <?= e($s['cls']) ?>"><?= e($s['label']) ?></span>
            <?php if ($n['responded_at']): ?>
              <br><span class="muted small"><?= e(date('d/m/Y H:i', (int)$n['responded_at'])) ?></span>
            <?php endif; ?>
          </td>
          <td><?php if ($n['reply']): ?><blockquote class="contest-reply"><?= nl2br(e($n['reply'])) ?></blockquote><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
