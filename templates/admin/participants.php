<?php
function _tier_label(float $usage): array {
    if ($usage < 0.50) return ['Prioritaire',  'tier-0'];
    if ($usage < 0.75) return ['Modéré',       'tier-1'];
    if ($usage < 0.90) return ['Fortement sollicité', 'tier-2'];
    return                    ['Saturé',       'tier-3'];
}
function _fmt_rel_date(?int $ts): string {
    if (!$ts) return '—';
    $delta = time() - $ts;
    if ($delta < 60) return 'à l\'instant';
    if ($delta < 3600) return floor($delta / 60) . ' min';
    if ($delta < 86400) return floor($delta / 3600) . ' h';
    if ($delta < 86400 * 7) return floor($delta / 86400) . ' j';
    return date('d/m/Y', $ts);
}

// Sépare les participants orphelins (zéro vote) du reste pour affichage.
$orphans = [];
$active  = [];
foreach ($rows as $r) {
    $total_votes = (int)$r['yes_count'] + (int)$r['maybe_count'] + (int)$r['no_count'];
    if ($total_votes === 0) $orphans[] = $r; else $active[] = $r;
}
$sum = ['yes' => 0, 'maybe' => 0, 'no' => 0, 'none' => 0, 'p' => 0, 'b' => 0];
?>

<h1>Participants</h1>
<p class="muted">
  Sondage : <a href="/admin/polls/<?= e($poll['uuid']) ?>"><?= e($poll['title']) ?></a>
  — <?= count($rows) ?> personnes (<?= count($active) ?> actives, <?= count($orphans) ?> orphelines),
  <?= $total_choices ?> créneaux au total
</p>
<p class="muted small">Cliquez sur un en-tête de colonne pour trier. Les flèches indiquent l'ordre.</p>

<?php if (!$active && !$orphans): ?>
  <p><em>Aucun participant pour l'instant.</em></p>
<?php else: ?>

<?php
$render_row = function (array $r, bool $is_orphan = false) use ($poll, $total_choices, &$sum) {
    $yes   = (int)$r['yes_count'];
    $maybe = (int)$r['maybe_count'];
    $no    = (int)$r['no_count'];
    $none  = max(0, $total_choices - $yes - $maybe - $no);
    $pri   = (int)$r['primary_count'];
    $bak   = (int)$r['backup_count'];
    $total = $pri + $bak;
    $cap   = $yes + 0.5 * $maybe;
    $usage = $cap > 0 ? $total / $cap : 0.0;
    [$tier_label, $tier_cls] = _tier_label($usage);
    $name  = $r['name'] !== '' ? $r['name'] : explode('@', $r['email'])[0];
    $vupd  = (int)($r['votes_updated_at'] ?? 0);
    $sum['yes']   += $yes;   $sum['maybe'] += $maybe; $sum['no'] += $no;
    $sum['none']  += $none;  $sum['p']     += $pri;   $sum['b']  += $bak;
?>
    <tr class="<?= $is_orphan ? 'orphan' : '' ?>">
      <td data-l="Nom" data-sort-value="<?= e(mb_strtolower($name)) ?>"><strong><?= e($name) ?></strong></td>
      <td data-l="Email" class="muted small"><?= e($r['email']) ?></td>
      <td data-l="Oui"       class="num v-yes-cell"><?= $yes ?></td>
      <td data-l="Peut-être" class="num v-maybe-cell"><?= $maybe ?></td>
      <td data-l="Non"       class="num v-no-cell"><?= $no ?></td>
      <td data-l="Sans rép." class="num muted"><?= $none ?></td>
      <td data-l="Principal·e" class="num"><?= $pri ?></td>
      <td data-l="Suppléant·e" class="num"><?= $bak ?></td>
      <td data-l="Total" class="num"><strong><?= $total ?></strong></td>
      <td data-l="Usage" class="usage-cell" data-sort-value="<?= (int)round($usage * 100) ?>">
        <?php if ($cap > 0): ?>
          <span class="usage-bar">
            <span class="usage-fill <?= e($tier_cls) ?>" style="width: <?= min(100, (int)round($usage*100)) ?>%"></span>
          </span>
          <span class="usage-pct <?= e($tier_cls) ?>"><?= (int)round($usage*100) ?>%</span>
          <br><span class="muted small"><?= e($tier_label) ?></span>
        <?php else: ?>
          <span class="muted small">—</span>
        <?php endif; ?>
      </td>
      <td data-l="Modif. votes" class="small" data-sort-value="<?= (int)$vupd ?>">
        <?php if ($vupd): ?>
          <span title="<?= e(date('d/m/Y H:i', $vupd)) ?>"><?= e(_fmt_rel_date($vupd)) ?></span>
        <?php else: ?>
          <span class="muted">—</span>
        <?php endif; ?>
      </td>
      <td data-l="Notif" data-sort-value="<?= e($r['notif_status'] ?? '') ?>">
        <?php if ($r['notif_status']): ?>
          <span class="notif-badge status-<?= e($r['notif_status']) ?>-row">
            <?= ['sent' => 'Envoyé', 'confirmed' => 'Confirmé', 'contested' => 'Signalement'][$r['notif_status']] ?? e($r['notif_status']) ?>
          </span>
        <?php else: ?>
          <span class="muted small">—</span>
        <?php endif; ?>
      </td>
      <td data-l="Actions" class="actions-cell">
        <a href="/admin/polls/<?= e($poll['uuid']) ?>/participants/<?= (int)$r['id'] ?>/calendar" class="link small">Calendrier</a>
        <a href="/admin/polls/<?= e($poll['uuid']) ?>/participants/<?= (int)$r['id'] ?>" class="link small">Éditer</a>
        <?php if ($total > 0): ?>
          <form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/assignments/notify" class="inline"
                onsubmit="return confirm('Renvoyer la notification d\'astreintes à <?= e(addslashes($name)) ?> ?');">
            <?= csrf_field() ?>
            <input type="hidden" name="target" value="one">
            <input type="hidden" name="participant_id" value="<?= (int)$r['id'] ?>">
            <button type="submit" class="link small">Renvoyer notif</button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
<?php
};
?>

<div class="grid-wrap">
<table class="participants-table sortable">
  <thead>
    <tr>
      <th>Nom ↕</th>
      <th>Email ↕</th>
      <th data-sort-type="num" title="Dates / créneaux où la personne a voté Oui"><span class="v-yes">Oui</span> ↕</th>
      <th data-sort-type="num" title="Peut-être"><span class="v-maybe">Peut-être</span> ↕</th>
      <th data-sort-type="num" title="Non"><span class="v-no">Non</span> ↕</th>
      <th data-sort-type="num" title="Pas de réponse">∅ ↕</th>
      <th data-sort-type="num" title="Astreintes principales attribuées">Principal·e ↕</th>
      <th data-sort-type="num" title="Astreintes suppléantes attribuées">Suppléant·e ↕</th>
      <th data-sort-type="num">Total ↕</th>
      <th data-sort-type="num" title="Total astreintes / capacité (Oui + ½ Peut-être)">Usage ↕</th>
      <th title="Dernière modification des votes">Maj votes ↕</th>
      <th>Notif ↕</th>
      <th class="no-sort">Actions</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($active as $r) $render_row($r); ?>
  </tbody>
  <?php if ($active): ?>
  <tfoot>
    <tr>
      <td colspan="2"><em>Totaux (<?= count($active) ?> actives)</em></td>
      <td class="num"><?= $sum['yes'] ?></td>
      <td class="num"><?= $sum['maybe'] ?></td>
      <td class="num"><?= $sum['no'] ?></td>
      <td class="num"><?= $sum['none'] ?></td>
      <td class="num"><?= $sum['p'] ?></td>
      <td class="num"><?= $sum['b'] ?></td>
      <td class="num"><strong><?= $sum['p'] + $sum['b'] ?></strong></td>
      <td colspan="4" class="muted small">
        couverture principale&nbsp;: <?= $total_choices > 0 ? round($sum['p'] * 100 / $total_choices) : 0 ?>%
        — suppléante&nbsp;: <?= $total_choices > 0 ? round($sum['b'] * 100 / $total_choices) : 0 ?>%
      </td>
    </tr>
  </tfoot>
  <?php endif; ?>
</table>
</div>

<?php if ($orphans): ?>
  <h3>Personnes orphelines (n'ont pas répondu) — <?= count($orphans) ?></h3>
  <p class="muted small">Ces participants existent en base mais n'ont saisi aucune réponse.
     Tu peux leur renvoyer un magic-link (via l'admin du sondage) ou les supprimer.</p>
  <div class="grid-wrap">
  <table class="participants-table sortable">
    <thead>
      <tr>
        <th>Nom ↕</th>
        <th>Email ↕</th>
        <th data-sort-type="num">Oui</th>
        <th data-sort-type="num">Peut-être</th>
        <th data-sort-type="num">Non</th>
        <th data-sort-type="num">Sans rép.</th>
        <th data-sort-type="num">Principal·e</th>
        <th data-sort-type="num">Suppléant·e</th>
        <th data-sort-type="num">Total</th>
        <th>Usage</th>
        <th>Maj votes ↕</th>
        <th>Notif</th>
        <th class="no-sort">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($orphans as $r) $render_row($r, true); ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>

<?php endif; ?>

<p><a href="/admin/polls/<?= e($poll['uuid']) ?>" class="link">← Retour au sondage</a></p>
