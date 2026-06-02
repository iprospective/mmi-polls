<?php
$active = 'calendar';
require __DIR__ . '/_admin_nav.php';

$months_fr = [
  1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril',
  5 => 'mai', 6 => 'juin', 7 => 'juillet', 8 => 'août',
  9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre',
];
$mon_num = (int)$first->format('n');
$year    = (int)$first->format('Y');
$label   = $months_fr[$mon_num] . ' ' . $year;
?>

<form method="get" class="card cal-filter">
  <label style="flex: 0 0 auto; margin: 0;">Voir le calendrier de :
    <select name="participant" onchange="this.form.submit()">
      <option value="0">— Tous les participants —</option>
      <?php foreach ($participants as $p):
        $pn = $p['name'] !== '' ? $p['name'] : explode('@', $p['email'])[0];
      ?>
        <option value="<?= (int)$p['id'] ?>" <?= $selected_pid === (int)$p['id'] ? 'selected' : '' ?>>
          <?= e($pn) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <input type="hidden" name="month" value="<?= e($month_str) ?>">
</form>

<div class="cal-nav">
  <a href="?month=<?= e($prev_month) ?>&participant=<?= $selected_pid ?>" class="link">← <?= e(date('M Y', strtotime($prev_month . '-01'))) ?></a>
  <h2 style="margin: 0; flex: 1; text-align: center;">
    <?= e(ucfirst($label)) ?>
    <?php if ($selected_participant): ?>
      — <span class="muted"><?= e($selected_participant['name'] !== '' ? $selected_participant['name'] : explode('@', $selected_participant['email'])[0]) ?></span>
    <?php endif; ?>
  </h2>
  <a href="?month=<?= e($next_month) ?>&participant=<?= $selected_pid ?>" class="link"><?= e(date('M Y', strtotime($next_month . '-01'))) ?> →</a>
</div>

<?php if ($poll_min): ?>
  <p class="muted small">Sondage : du <?= e($poll_min) ?> au <?= e($poll_max) ?>.
     <a href="?month=<?= e(substr($poll_min, 0, 7)) ?>&participant=<?= $selected_pid ?>" class="link">aller au premier mois</a>
     · <a href="?month=<?= e(date('Y-m')) ?>&participant=<?= $selected_pid ?>" class="link">mois courant</a>
  </p>
<?php endif; ?>

<?php if ($enable_dnd): ?>
<p class="muted small">
  💡 Glissez-déposez une assignation pour la déplacer ou échanger.
  Une demande de validation sera envoyée par email à la·aux personne(s) concernée(s).
</p>
<?php endif; ?>

<?php
// Charge le partial — utilise les variables $first, $mon_num, $by_day,
// $by_day_meta, $dates_by_day, $enable_dnd, $poll_min, $poll_max
// déjà en scope ici.
require __DIR__ . '/../_month_calendar.php';
?>

<?php if (!empty($pending_moves)): ?>
<h3>Demandes d'échange en cours (<?= count($pending_moves) ?>)</h3>
<div class="grid-wrap">
<table class="dates-table notif-status">
  <thead>
    <tr>
      <th>Initiée</th>
      <th>Source</th>
      <th>Cible</th>
      <th>Réponses</th>
      <th class="no-sort">Actions</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($pending_moves as $m):
      $src_n = $m['src_name'] !== '' ? $m['src_name'] : explode('@', $m['src_email'])[0];
      $dst_n = $m['dst_name'] ? ($m['dst_name'] !== '' ? $m['dst_name'] : explode('@', $m['dst_email'] ?? '', 2)[0]) : '';
      $awaiting = max(0, (int)$m['n_total'] - (int)$m['n_accept']);
    ?>
      <tr>
        <td class="small"><?= e(date('d/m/Y H:i', (int)$m['created_at'])) ?></td>
        <td>
          <strong><?= e($src_n) ?></strong><br>
          <span class="muted small">
            <?= e(fmt_day($m['src_day'])) ?> · <?= e($m['src_label']) ?>
            (<?= $m['src_role'] === 'primary' ? 'P' : 'S' ?>)
          </span>
        </td>
        <td>
          <?php if ($m['dst_pid']): ?>
            <strong><?= e($dst_n) ?></strong> <span class="muted small">(échange)</span><br>
          <?php else: ?>
            <em class="muted">(case vide)</em><br>
          <?php endif; ?>
          <span class="muted small">
            <?= e(fmt_day($m['dst_day'])) ?> · <?= e($m['dst_label']) ?>
            (<?= $m['dst_role'] === 'primary' ? 'P' : 'S' ?>)
          </span>
        </td>
        <td class="small">
          <strong><?= (int)$m['n_accept'] ?>/<?= (int)$m['n_total'] ?></strong> accepté ·
          <?= $awaiting ?> en attente
        </td>
        <td class="actions-cell">
          <form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/move/<?= (int)$m['id'] ?>/cancel" class="inline"
                onsubmit="return confirm('Annuler cette demande ? Les personnes concernées seront prévenues.');">
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

<?php if ($enable_dnd): ?>
<dialog id="move-modal" class="card move-modal">
  <h2 style="margin-top: 0;">Demande de déplacement / d'échange</h2>
  <p id="move-summary" class="muted"></p>
  <form id="move-form" method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/calendar/move">
    <?= csrf_field() ?>
    <input type="hidden" name="src_cid" id="move-src-cid">
    <input type="hidden" name="src_role" id="move-src-role">
    <input type="hidden" name="dst_cid" id="move-dst-cid">
    <input type="hidden" name="dst_role" id="move-dst-role">
    <label>Message à joindre (facultatif)
      <textarea name="message" id="move-message" rows="3" placeholder="ex. Tu pourrais récupérer le créneau de Bob ?"></textarea>
    </label>
    <div class="row">
      <button type="submit" id="move-submit">📨 Envoyer la demande</button>
      <button type="button" id="move-cancel" class="link">Annuler</button>
    </div>
  </form>
</dialog>
<?php endif; ?>
