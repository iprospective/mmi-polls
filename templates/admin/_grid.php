<?php
// Shared grid renderer.
// Expects: $dates, $participants, $votes (map participant_id => choice_id => value)
$symbols = ['yes' => '✓', 'no' => '✗', 'maybe' => '?'];
$hl = $GLOBALS['CONFIG']['highlight'] ?? ['yes_min' => 2, 'yesmaybe_min' => 2];
$yes_min      = (int)$hl['yes_min'];
$yesmaybe_min = (int)$hl['yesmaybe_min'];

// Calcule en amont les comptes / statut par créneau et l'agrégat par jour.
// Le statut "jour" est le pire des statuts de ses créneaux
// (ok < warn < bad) → la date ne passe au vert que si TOUS ses créneaux le sont.
$row_counts = [];
$row_status = [];
$day_status = [];
$status_rank = ['ok' => 0, 'warn' => 1, 'bad' => 2];
foreach ($dates as $d) {
    $worst = null;
    foreach ($d['choices'] as $c) {
        $counts = ['yes' => 0, 'no' => 0, 'maybe' => 0];
        foreach ($participants as $p) {
            $v = $votes[$p['id']][$c['id']] ?? null;
            if ($v && isset($counts[$v])) $counts[$v]++;
        }
        if ($counts['yes'] >= $yes_min) {
            $st = 'ok';
        } elseif (($counts['yes'] + $counts['maybe']) >= $yesmaybe_min) {
            $st = 'warn';
        } else {
            $st = 'bad';
        }
        $row_counts[$c['id']] = $counts;
        $row_status[$c['id']] = $st;
        if ($worst === null || $status_rank[$st] > $status_rank[$worst]) {
            $worst = $st;
        }
    }
    $day_status[$d['id']] = $worst ?? 'bad';
}
?>
<div class="grid-wrap">
<table class="vote-grid">
  <thead>
    <tr>
      <th class="date-col">Date</th>
      <th class="slot-col">Créneau</th>
      <?php foreach ($participants as $p):
        $full  = $p['name'] !== '' ? $p['name'] : explode('@', $p['email'])[0];
        $short = mb_substr($full, 0, 3);
      ?>
        <th class="participant" title="<?= e($full) ?>"><?= e($short) ?>…</th>
      <?php endforeach; ?>
      <th class="summary-col">Récap</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($dates as $d):
      $rows   = count($d['choices']);
      $first  = true;
      $day_st = $day_status[$d['id']];
      foreach ($d['choices'] as $c):
        $status = $row_status[$c['id']];
        $counts = $row_counts[$c['id']];
    ?>
      <tr class="row-<?= $status ?><?= $first ? ' day-first' : '' ?>">
        <?php if ($first): ?>
          <th class="date-cell status-<?= $day_st ?>" rowspan="<?= $rows ?>">
            <?= e(fmt_day($d['day'])) ?><br>
            <span class="muted small"><?= e($d['day']) ?></span>
          </th>
        <?php endif; $first = false; ?>
        <td class="slot-cell"><?= e($c['label']) ?></td>
        <?php foreach ($participants as $p):
          $v = $votes[$p['id']][$c['id']] ?? null;
          $cls = $v ? 'v-' . $v : 'v-none';
          $sym = $v ? $symbols[$v] : '—';
        ?>
          <td class="vote-cell <?= $cls ?>"><?= $sym ?></td>
        <?php endforeach; ?>
        <td class="summary-cell status-<?= $status ?>">
          <span class="v-yes"><?= $counts['yes'] ?>✓</span>
          <span class="v-maybe"><?= $counts['maybe'] ?>?</span>
          <span class="v-no"><?= $counts['no'] ?>✗</span>
        </td>
      </tr>
    <?php endforeach; endforeach; ?>
  </tbody>
</table>
</div>

<?php
// Vue alternative pour mobile : un bloc par créneau, avec récap pliable.
// CSS toggles : .grid-wrap visible >700px, .mobile-grid visible <=700px.
?>
<ul class="mobile-grid">
<?php foreach ($dates as $d):
  $day_st = $day_status[$d['id']];
?>
  <li class="m-day-header status-<?= e($day_st) ?>">
    <strong><?= e(fmt_day($d['day'])) ?></strong>
    <span class="muted small"><?= e($d['day']) ?></span>
  </li>
  <?php foreach ($d['choices'] as $c):
    $cid = (int)$c['id'];
    $status = $row_status[$cid];
    $counts = $row_counts[$cid];
    // Regroupe les voteurs par valeur, pour la zone repliable.
    $by_value = ['yes' => [], 'maybe' => [], 'no' => []];
    foreach ($participants as $p) {
      $v = $votes[$p['id']][$cid] ?? null;
      if ($v && isset($by_value[$v])) {
        $by_value[$v][] = $p['name'] !== '' ? $p['name'] : explode('@', $p['email'])[0];
      }
    }
    $total_votes = $counts['yes'] + $counts['no'] + $counts['maybe'];
  ?>
  <li class="m-slot row-<?= $status ?>">
    <div class="m-head">
      <span class="m-label"><?= e($c['label']) ?></span>
      <span class="m-recap">
        <span class="v-yes"><?= $counts['yes'] ?>✓</span>
        <span class="v-maybe"><?= $counts['maybe'] ?>?</span>
        <span class="v-no"><?= $counts['no'] ?>✗</span>
      </span>
    </div>
    <?php if ($total_votes > 0): ?>
    <details class="m-detail">
      <summary>Qui ?</summary>
      <?php foreach (['yes' => '✓', 'maybe' => '?', 'no' => '✗'] as $val => $sym): ?>
        <?php if (!empty($by_value[$val])): ?>
          <div class="m-grp v-<?= $val ?>"><?= $sym ?> <?= e(implode(', ', $by_value[$val])) ?></div>
        <?php endif; ?>
      <?php endforeach; ?>
    </details>
    <?php endif; ?>
  </li>
  <?php endforeach; ?>
<?php endforeach; ?>
</ul>
