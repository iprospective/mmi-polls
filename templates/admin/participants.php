<?php
// Calcule l'usage % par participant pour le badge de tier
// (cohérent avec l'algo auto-fill).
function _tier_label(float $usage): array {
    if ($usage < 0.50) return ['Prioritaire',  'tier-0'];
    if ($usage < 0.75) return ['Modéré',       'tier-1'];
    if ($usage < 0.90) return ['Fortement sollicité', 'tier-2'];
    return                    ['Saturé',       'tier-3'];
}
?>

<h1>Participants</h1>
<p class="muted">
  Sondage : <a href="/admin/polls/<?= e($poll['uuid']) ?>"><?= e($poll['title']) ?></a>
  — <?= count($rows) ?> personnes, <?= $total_choices ?> créneaux au total
</p>

<?php if (!$rows): ?>
  <p><em>Aucun participant pour l'instant.</em></p>
<?php else: ?>
<div class="grid-wrap">
<table class="participants-table">
  <thead>
    <tr>
      <th>Nom</th>
      <th>Email</th>
      <th title="Dates / créneaux où la personne a voté Oui"><span class="v-yes">Oui</span></th>
      <th title="Peut-être"><span class="v-maybe">Peut-être</span></th>
      <th title="Non"><span class="v-no">Non</span></th>
      <th title="Pas de réponse">∅</th>
      <th title="Astreintes principales attribuées">Principal·e</th>
      <th title="Astreintes suppléantes attribuées">Suppléant·e</th>
      <th>Total</th>
      <th title="Total astreintes / capacité (Oui + ½ Peut-être)">Usage</th>
      <th>Notif</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php
    $sum = ['yes' => 0, 'maybe' => 0, 'no' => 0, 'none' => 0, 'p' => 0, 'b' => 0];
    foreach ($rows as $r):
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

      $sum['yes'] += $yes; $sum['maybe'] += $maybe; $sum['no'] += $no;
      $sum['none'] += $none; $sum['p'] += $pri; $sum['b'] += $bak;
  ?>
    <tr>
      <td><strong><?= e($name) ?></strong></td>
      <td class="muted small"><?= e($r['email']) ?></td>
      <td class="num v-yes-cell"   data-l="Oui">      <?= $yes ?></td>
      <td class="num v-maybe-cell" data-l="Peut-être"><?= $maybe ?></td>
      <td class="num v-no-cell"    data-l="Non">      <?= $no ?></td>
      <td class="num muted"        data-l="Sans rép.">  <?= $none ?></td>
      <td class="num" data-l="Principal·e"><?= $pri ?></td>
      <td class="num" data-l="Suppléant·e"><?= $bak ?></td>
      <td class="num" data-l="Total"><strong><?= $total ?></strong></td>
      <td class="usage-cell" data-l="Usage">
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
      <td>
        <?php if ($r['notif_status']): ?>
          <span class="notif-badge status-<?= e($r['notif_status']) ?>-row">
            <?= ['sent' => 'Envoyé', 'confirmed' => 'Confirmé', 'contested' => 'Signalement'][$r['notif_status']] ?? e($r['notif_status']) ?>
          </span>
        <?php else: ?>
          <span class="muted small">—</span>
        <?php endif; ?>
      </td>
      <td>
        <a href="/admin/polls/<?= e($poll['uuid']) ?>/participants/<?= (int)$r['id'] ?>" class="link small">Éditer</a>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr>
      <td colspan="2"><em>Totaux (<?= count($rows) ?> pers.)</em></td>
      <td class="num"><?= $sum['yes'] ?></td>
      <td class="num"><?= $sum['maybe'] ?></td>
      <td class="num"><?= $sum['no'] ?></td>
      <td class="num"><?= $sum['none'] ?></td>
      <td class="num"><?= $sum['p'] ?></td>
      <td class="num"><?= $sum['b'] ?></td>
      <td class="num"><strong><?= $sum['p'] + $sum['b'] ?></strong></td>
      <td colspan="3" class="muted small">
        couverture principale&nbsp;: <?= $total_choices > 0 ? round($sum['p'] * 100 / $total_choices) : 0 ?>%
        — suppléante&nbsp;: <?= $total_choices > 0 ? round($sum['b'] * 100 / $total_choices) : 0 ?>%
      </td>
    </tr>
  </tfoot>
</table>
</div>
<?php endif; ?>

<p><a href="/admin/polls/<?= e($poll['uuid']) ?>" class="link">← Retour au sondage</a></p>
