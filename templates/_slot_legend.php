<?php
// Partial : affiche les horaires des créneaux du sondage + une note
// concise sur le chevauchement intentionnel, à destination des
// participant·e·s. Variables attendues : $poll, $dates.

$used = [];
foreach ($dates as $d) foreach ($d['choices'] as $c) {
    if (!in_array($c['label'], $used, true)) $used[] = $c['label'];
}
$map = poll_slot_hours_map($poll);
$entries = [];
foreach ($used as $lbl) {
    $h = $map[mb_strtolower($lbl)] ?? null;
    if ($h) $entries[$lbl] = $h;
}
if (!$entries) return;
?>

<details class="card slot-legend">
  <summary><strong>⏰ Horaires des créneaux</strong></summary>
  <ul class="slot-legend-list">
    <?php foreach ($entries as $lbl => $h):
      $next_day = ($h['end'] <= $h['start']);
    ?>
      <li>
        <strong><?= e($lbl) ?></strong>
        <span class="muted">— de</span>
        <code><?= e($h['start']) ?></code>
        <span class="muted">à</span>
        <code><?= e($h['end']) ?></code>
        <?php if ($next_day): ?><span class="muted small">(le lendemain)</span><?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <p class="muted small slot-legend-note">
    💡 Les créneaux se chevauchent <strong>exprès</strong>&nbsp;: ça donne à la personne précédente une marge pour finir son trajet, et tu prends officiellement le relai à l'horaire de début affiché. Pas de stress en cas d'appel tardif, on s'organise mieux comme ça.
  </p>
</details>
