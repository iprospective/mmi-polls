<?php
$months_fr = [
  1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril',
  5 => 'mai', 6 => 'juin', 7 => 'juillet', 8 => 'août',
  9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre',
];
$mon_num = (int)$first->format('n');
$label   = $months_fr[$mon_num] . ' ' . (int)$first->format('Y');
?>

<h1>Mon calendrier</h1>
<p class="muted">
  Sondage : <a href="/p/<?= e($poll['uuid']) ?>"><?= e($poll['title']) ?></a>
  · <a href="/p/<?= e($poll['uuid']) ?>/me" class="link">← Mes disponibilités</a>
</p>

<div class="cal-nav">
  <a href="?month=<?= e($prev_month) ?>" class="link">← <?= e(date('M Y', strtotime($prev_month . '-01'))) ?></a>
  <h2 style="margin: 0; flex: 1; text-align: center;"><?= e(ucfirst($label)) ?></h2>
  <a href="?month=<?= e($next_month) ?>" class="link"><?= e(date('M Y', strtotime($next_month . '-01'))) ?> →</a>
</div>

<?php require __DIR__ . '/../_month_calendar.php'; ?>
