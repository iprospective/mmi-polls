<?php
// Partial : grille mensuelle d'un calendrier d'astreintes.
// Variables attendues dans le scope :
//   $first       DateTimeImmutable du 1er du mois
//   $mon_num     int (1..12)
//   $by_day      [day][slot][role] => name  (ex. ['2026-06-01']['Soirée']['primary'] = 'Aude')
//   $poll_min    string 'YYYY-MM-DD' (optionnel, '' si pas de bornes)
//   $poll_max    string 'YYYY-MM-DD' (optionnel, '' si pas de bornes)
//   $hide_role   string|null  'backup' pour masquer la ligne S (vue suppléante moins lourde) — optionnel

$months_fr = [
  1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril',
  5 => 'mai', 6 => 'juin', 7 => 'juillet', 8 => 'août',
  9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre',
];
$year  = (int)$first->format('Y');
$label = $months_fr[$mon_num] . ' ' . $year;

$last = $first->modify('last day of this month');
$first_weekday = (int)$first->format('N');
$grid_start = $first->modify('-' . ($first_weekday - 1) . ' days');
$last_weekday  = (int)$last->format('N');
$grid_end   = $last->modify('+' . (7 - $last_weekday) . ' days');
$today = date('Y-m-d');
$poll_min = $poll_min ?? '';
$poll_max = $poll_max ?? '';
?>

<div class="grid-wrap">
<table class="month-cal">
  <thead>
    <tr>
      <?php foreach (['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'] as $d): ?>
        <th><?= e($d) ?></th>
      <?php endforeach; ?>
    </tr>
  </thead>
  <tbody>
    <?php
    $cur = $grid_start;
    while ($cur <= $grid_end):
      if ((int)$cur->format('N') === 1) echo "<tr>";
      $day = $cur->format('Y-m-d');
      $is_other_month = (int)$cur->format('n') !== $mon_num;
      $is_today       = $day === $today;
      $is_in_poll     = ($poll_min && $day >= $poll_min && $day <= $poll_max);
      $assigns_for_day = $by_day[$day] ?? [];
    ?>
      <td class="<?= $is_other_month ? 'cal-other' : '' ?>
                 <?= $is_today ? 'cal-today' : '' ?>
                 <?= $is_in_poll ? 'cal-in-poll' : '' ?>">
        <div class="cal-day-head">
          <span class="cal-day-num"><?= (int)$cur->format('j') ?></span>
          <?php if ($is_other_month): ?>
            <span class="cal-day-month muted small"><?= e($cur->format('M')) ?></span>
          <?php endif; ?>
        </div>
        <?php if ($assigns_for_day): ?>
          <ul class="cal-slots">
            <?php foreach ($assigns_for_day as $slot => $roles): ?>
              <li>
                <span class="cal-slot-label"><?= e($slot) ?></span>
                <?php if (isset($roles['primary'])): ?>
                  <span class="cal-pair">
                    <span class="role-marker rm-primary" title="Principal·e">P</span><span class="cal-name"><?= e($roles['primary']) ?></span>
                  </span>
                <?php else: ?>
                  <span class="cal-pair">
                    <span class="role-marker rm-primary cal-empty" title="Pas de principal·e">P</span><span class="cal-empty-text">—</span>
                  </span>
                <?php endif; ?>
                <?php if (isset($roles['backup']) && empty($hide_role)): ?>
                  <span class="cal-pair">
                    <span class="role-marker rm-backup" title="Suppléant·e">S</span><span class="cal-name"><?= e($roles['backup']) ?></span>
                  </span>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </td>
    <?php
      if ((int)$cur->format('N') === 7) echo "</tr>";
      $cur = $cur->modify('+1 day');
    endwhile;
    ?>
  </tbody>
</table>
</div>
