<h1>Mes sondages</h1>
<p class="muted">Connecté·e en tant que <strong><?= e($manager['name'] !== '' ? $manager['name'] : $manager['email']) ?></strong>.</p>

<form method="post" action="/admin/polls" class="card">
  <?= csrf_field() ?>
  <h2 style="margin-top:0;">Nouveau sondage</h2>
  <label>Titre
    <input type="text" name="title" required>
  </label>
  <label>Description
    <textarea name="description" data-wysiwyg style="display:none"></textarea>
    <div class="wysiwyg-editor"></div>
  </label>
  <button type="submit">Créer</button>
</form>

<?php if (!$polls): ?>
  <p><em>Vous n'avez pas encore créé de sondage.</em></p>
<?php else: ?>
  <ul class="poll-list">
    <?php foreach ($polls as $p): ?>
      <li>
        <a href="/admin/polls/<?= e($p['uuid']) ?>"><strong><?= e($p['title']) ?></strong></a>
        <span class="muted">— créé le <?= e(date('Y-m-d', (int)$p['created_at'])) ?></span>
        <div class="muted small">Lien public : <a href="/p/<?= e($p['uuid']) ?>">/p/<?= e($p['uuid']) ?></a></div>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
