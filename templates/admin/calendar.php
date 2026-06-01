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

<?php
// Charge le partial — utilise les variables $first, $mon_num, $by_day,
// $poll_min, $poll_max déjà en scope ici.
require __DIR__ . '/../_month_calendar.php';
?>
