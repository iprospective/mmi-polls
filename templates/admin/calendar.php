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

// Padding début de mois : on remonte au lundi qui précède le 1er du mois.
$first_weekday = (int)$first->format('N'); // 1 = lundi
$grid_start = $first->modify('-' . ($first_weekday - 1) . ' days');
// Padding fin de mois : on descend au dimanche qui suit le dernier du mois.
$last_weekday = (int)$last->format('N');
$grid_end = $last->modify('+' . (7 - $last_weekday) . ' days');

$today = date('Y-m-d');
?>

<div class="cal-nav">
  <a href="?month=<?= e($prev_month) ?>" class="link">← <?= e(date('M Y', strtotime($prev_month . '-01'))) ?></a>
  <h2 style="margin: 0; flex: 1; text-align: center;"><?= e(ucfirst($label)) ?></h2>
  <a href="?month=<?= e($next_month) ?>" class="link"><?= e(date('M Y', strtotime($next_month . '-01'))) ?> →</a>
</div>

<?php if ($poll_min): ?>
  <p class="muted small">Sondage : du <?= e($poll_min) ?> au <?= e($poll_max) ?>.
     <a href="?month=<?= e(substr($poll_min, 0, 7)) ?>" class="link">aller au premier mois</a>
     · <a href="?month=<?= e(date('Y-m')) ?>" class="link">mois courant</a>
  </p>
<?php endif; ?>

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
      // Démarre une nouvelle ligne au lundi
      if ((int)$cur->format('N') === 1) echo "<tr>";
      $day = $cur->format('Y-m-d');
      $is_other_month = (int)$cur->format('n') !== $mon_num;
      $is_today  = $day === $today;
      $is_in_poll = ($day >= $poll_min && $day <= $poll_max);
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
                  <span class="role-marker rm-primary" title="Principal·e">P</span>
                  <span class="cal-name"><?= e($roles['primary']) ?></span>
                <?php else: ?>
                  <span class="role-marker rm-primary cal-empty" title="Pas de principal·e">P</span>
                  <span class="cal-empty-text">—</span>
                <?php endif; ?>
                <?php if (isset($roles['backup'])): ?>
                  <span class="role-marker rm-backup" title="Suppléant·e">S</span>
                  <span class="cal-name"><?= e($roles['backup']) ?></span>
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
