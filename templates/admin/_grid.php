<?php
// Shared grid renderer.
// Expects: $dates, $participants, $votes (map participant_id => choice_id => value)
$symbols = ['yes' => '✓', 'no' => '✗', 'maybe' => '?'];
?>
<div class="grid-wrap">
<table class="vote-grid">
  <thead>
    <tr>
      <th class="date-col">Date</th>
      <th class="slot-col">Créneau</th>
      <?php foreach ($participants as $p): ?>
        <th class="participant"><?= e($p['name'] !== '' ? $p['name'] : explode('@', $p['email'])[0]) ?></th>
      <?php endforeach; ?>
      <th class="summary-col">Récap</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($dates as $d):
      $rows = count($d['choices']);
      $first = true;
      foreach ($d['choices'] as $c):
        $counts = ['yes' => 0, 'no' => 0, 'maybe' => 0];
        foreach ($participants as $p) {
          $v = $votes[$p['id']][$c['id']] ?? null;
          if ($v && isset($counts[$v])) $counts[$v]++;
        }
    ?>
      <tr>
        <?php if ($first): ?>
          <th class="date-cell" rowspan="<?= $rows ?>"><?= e(fmt_day($d['day'])) ?><br><span class="muted small"><?= e($d['day']) ?></span></th>
        <?php endif; $first = false; ?>
        <td class="slot-cell"><?= e($c['label']) ?></td>
        <?php foreach ($participants as $p):
          $v = $votes[$p['id']][$c['id']] ?? null;
          $cls = $v ? 'v-' . $v : 'v-none';
          $sym = $v ? $symbols[$v] : '—';
        ?>
          <td class="vote-cell <?= $cls ?>"><?= $sym ?></td>
        <?php endforeach; ?>
        <td class="summary-cell">
          <span class="v-yes"><?= $counts['yes'] ?>✓</span>
          <span class="v-maybe"><?= $counts['maybe'] ?>?</span>
          <span class="v-no"><?= $counts['no'] ?>✗</span>
        </td>
      </tr>
    <?php endforeach; endforeach; ?>
  </tbody>
</table>
</div>
