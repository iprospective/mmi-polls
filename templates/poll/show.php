<h1>
  <?= e($poll['title']) ?>
  <?php if (poll_is_closed($poll)): ?>
    <span class="closed-badge">🔒 clos</span>
  <?php endif; ?>
</h1>

<?php if ($poll['description'] !== ''): ?>
  <div class="card description"><?= render_description($poll['description']) ?></div>
<?php endif; ?>

<?php require __DIR__ . '/../_slot_legend.php'; ?>

<div class="card actions">
  <?php if ($me): ?>
    <p>Connecté·e en tant que <strong><?= e($me['email']) ?></strong>.</p>
    <p>
      <a href="/p/<?= e($poll['uuid']) ?>/me" class="btn">Modifier mes choix</a>
      <form method="post" action="/p/<?= e($poll['uuid']) ?>/logout" class="inline">
        <?= csrf_field() ?>
        <button type="submit" class="link">Se déconnecter</button>
      </form>
    </p>
  <?php else: ?>
    <p><a href="/p/<?= e($poll['uuid']) ?>/login" class="btn">Donner mes disponibilités</a></p>
  <?php endif; ?>
</div>

<?php if (!$dates): ?>
  <p><em>Le sondage ne contient encore aucune date.</em></p>
<?php elseif (!$participants): ?>
  <p><em>Aucune réponse pour l'instant. Soyez le premier !</em></p>
<?php else: ?>
  <?php require __DIR__ . '/../admin/_grid.php'; ?>
<?php endif; ?>
