<?php
$active = 'home';
$public_url = rtrim($GLOBALS['CONFIG']['app_url'], '/') . '/p/' . $poll['uuid'];
require __DIR__ . '/_admin_nav.php';
?>

<div class="card admin-summary">
  <p>
    Lien public :
    <a href="<?= e($public_url) ?>"><code><?= e($public_url) ?></code></a>
  </p>
  <p class="muted small">
    <?= count($dates) ?> dates ·
    <?php $nb_choices = 0; foreach ($dates as $d) $nb_choices += count($d['choices']); ?>
    <?= $nb_choices ?> créneaux ·
    <?= count($participants) ?> participants
  </p>
</div>

<h2 style="margin-top:1.5rem;">Réponses</h2>
<?php if ($participants && $dates): ?>
  <?php require __DIR__ . '/_grid.php'; ?>
<?php elseif (!$dates): ?>
  <p><em>Pas encore de dates. Va sur <a href="/admin/polls/<?= e($poll['uuid']) ?>/dates">Dates &amp; créneaux</a> pour en ajouter.</em></p>
<?php else: ?>
  <p><em>Pas encore de réponses.</em></p>
<?php endif; ?>
