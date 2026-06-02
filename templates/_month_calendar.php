<?php
// Partial : grille mensuelle d'un calendrier d'astreintes.
// Variables attendues dans le scope :
//   $first       DateTimeImmutable du 1er du mois
//   $mon_num     int (1..12)
//   $by_day      [day][slot][role] => name  (ex. ['2026-06-01']['Soirée']['primary'] = 'Aude')
//   $poll_min    string 'YYYY-MM-DD' (optionnel, '' si pas de bornes)
//   $poll_max    string 'YYYY-MM-DD' (optionnel, '' si pas de bornes)
//   $hide_role   string|null  'backup' pour masquer la ligne S — optionnel
//
// Mode drag'n'drop (admin uniquement, optionnel) :
//   $enable_dnd     bool          active draggable + drop targets sur chaque case
//   $by_day_meta    [day][slot][role] => ['pid'=>N, 'choice_id'=>N]
//   $dates_by_day   [day] => [['choice_id'=>N, 'label'=>'Soirée'], …]
//                   permet d'afficher TOUS les slots du jour (même vides)
$enable_dnd = !empty($enable_dnd ?? false);
$by_day_meta = $by_day_meta ?? [];
$dates_by_day = $dates_by_day ?? [];

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
        <?php
        // En mode DnD, on liste TOUS les slots du jour (même vides) pour
        // permettre le drop sur cases vides. Sinon, mode classique : seulement
        // les slots qui ont au moins une assignation.
        if ($enable_dnd && isset($dates_by_day[$day])) {
            $slots_for_day = [];
            foreach ($dates_by_day[$day] as $sl) {
                $slots_for_day[$sl['label']] = ['choice_id' => $sl['choice_id']];
            }
        } else {
            $slots_for_day = [];
            foreach ($assigns_for_day as $label => $_r) $slots_for_day[$label] = null;
        }
        ?>
        <?php if ($slots_for_day): ?>
          <ul class="cal-slots <?= $enable_dnd ? 'dnd-mode' : '' ?>">
            <?php foreach ($slots_for_day as $slot => $slot_meta):
              $roles = $assigns_for_day[$slot] ?? [];
              $meta  = $by_day_meta[$day][$slot] ?? [];
              $cid   = $slot_meta['choice_id'] ?? ($meta['primary']['choice_id'] ?? $meta['backup']['choice_id'] ?? 0);
              // Skip un slot complètement vide en mode lecture seule
              if (!$enable_dnd && empty($roles)) continue;
            ?>
              <li>
                <span class="cal-slot-label"><?= e($slot) ?></span>
                <?php foreach (['primary' => 'P', 'backup' => 'S'] as $role => $letter):
                  if ($role === 'backup' && !empty($hide_role)) continue;
                  $has = isset($roles[$role]);
                  $r_meta = $meta[$role] ?? [];
                  $r_pid  = (int)($r_meta['pid'] ?? 0);
                  $r_cid  = (int)($r_meta['choice_id'] ?? $cid);
                  $dnd_attrs = '';
                  if ($enable_dnd && $r_cid > 0) {
                      $dnd_attrs = ' data-cid="' . (int)$r_cid . '" data-role="' . e($role) . '" data-pid="' . (int)$r_pid . '" data-slot-label="' . e($slot) . '" data-day="' . e($day) . '"';
                  }
                  $extra_cls = $enable_dnd ? ' dnd-cell ' . ($has ? 'dnd-draggable' : 'dnd-empty') : '';
                ?>
                  <?php if ($has): ?>
                    <span class="cal-pair<?= $extra_cls ?>"<?= $dnd_attrs ?> <?= $enable_dnd ? 'draggable="true"' : '' ?>>
                      <span class="role-marker rm-<?= $role ?>" title="<?= $role === 'primary' ? 'Principal·e' : 'Suppléant·e' ?>"><?= $letter ?></span><span class="cal-name"><?= e($roles[$role]) ?></span>
                    </span>
                  <?php elseif ($role === 'primary' || $enable_dnd): ?>
                    <span class="cal-pair cal-empty-pair<?= $extra_cls ?>"<?= $dnd_attrs ?>>
                      <span class="role-marker rm-<?= $role ?> cal-empty" title="<?= $role === 'primary' ? 'Pas de principal·e' : 'Pas de suppléant·e' ?>"><?= $letter ?></span><span class="cal-empty-text">—</span>
                    </span>
                  <?php endif; ?>
                <?php endforeach; ?>
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
