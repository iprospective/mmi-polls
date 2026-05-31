<?php
$public_url = rtrim($GLOBALS['CONFIG']['app_url'], '/') . '/p/' . $poll['uuid'];
?>
<h1><?= e($poll['title']) ?></h1>

<div class="card">
  <p>
    Lien public :
    <a href="<?= e($public_url) ?>"><code><?= e($public_url) ?></code></a>
  </p>
  <p>
    <a href="/admin/polls/<?= e($poll['uuid']) ?>/assignments" class="btn">Gérer les astreintes</a>
    <a href="/admin/polls/<?= e($poll['uuid']) ?>/participants" class="btn btn-ghost">Vue participants</a>
  </p>
</div>

<details class="card">
  <summary><strong>Modifier titre / description</strong></summary>
  <form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>">
    <?= csrf_field() ?>
    <label>Titre <input type="text" name="title" value="<?= e($poll['title']) ?>" required></label>
    <label>Description
      <textarea name="description" data-wysiwyg style="display:none"><?= e($poll['description']) ?></textarea>
      <div class="wysiwyg-editor"></div>
    </label>
    <button type="submit">Enregistrer</button>
  </form>
</details>

<details class="card">
  <summary><strong>Ajouter une date</strong></summary>
  <form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/dates">
    <?= csrf_field() ?>
    <label>Date (AAAA-MM-JJ) <input type="date" name="day" required></label>
    <label>Créneaux (un par ligne)
      <textarea name="choices" rows="4" placeholder="Journée&#10;Soirée&#10;Nuit" required></textarea>
    </label>
    <button type="submit">Ajouter</button>
  </form>
</details>

<details class="card">
  <summary><strong>Zone dangereuse</strong></summary>
  <form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/delete"
        onsubmit="return confirm('Supprimer définitivement ce sondage ?');">
    <?= csrf_field() ?>
    <button type="submit" class="danger">Supprimer le sondage</button>
  </form>
</details>

<h2>Dates &amp; créneaux</h2>

<?php if (!$dates): ?>
  <p><em>Aucune date pour l'instant.</em></p>
<?php else: ?>
  <table class="dates-table">
    <thead>
      <tr><th>Jour</th><th>Créneaux</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($dates as $d): ?>
      <tr>
        <td><strong><?= e(fmt_day($d['day'])) ?></strong><br><span class="muted small"><?= e($d['day']) ?></span></td>
        <td>
          <ul class="choices">
            <?php foreach ($d['choices'] as $c): ?>
              <li>
                <span><?= e($c['label']) ?></span>
                <form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/choices/<?= (int)$c['id'] ?>/delete" class="inline"
                      onsubmit="return confirm('Supprimer ce créneau ?');">
                  <?= csrf_field() ?>
                  <button type="submit" class="link small">×</button>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
          <form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/dates/<?= (int)$d['id'] ?>/choices" class="row">
            <?= csrf_field() ?>
            <input type="text" name="label" placeholder="Nouveau créneau" required>
            <button type="submit">+</button>
          </form>
        </td>
        <td>
          <form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/dates/<?= (int)$d['id'] ?>/delete"
                onsubmit="return confirm('Supprimer cette date et tous ses créneaux ?');">
            <?= csrf_field() ?>
            <button type="submit" class="danger small">Supprimer</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<h2>Réponses (<?= count($participants) ?>)</h2>

<?php if ($participants && $dates): ?>
  <?php require __DIR__ . '/_grid.php'; ?>
<?php elseif (!$dates): ?>
  <p><em>Ajoutez d'abord des dates avant d'enregistrer des participants.</em></p>
<?php endif; ?>

<h3>Participants</h3>

<form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/participants" class="card">
  <?= csrf_field() ?>
  <strong>Ajouter un participant</strong>
  <div class="row">
    <input type="text"  name="name"  placeholder="Nom (facultatif)">
    <input type="email" name="email" placeholder="email@exemple.com" required>
    <button type="submit">Ajouter</button>
  </div>
  <p class="muted small">Après création vous pourrez saisir ses disponibilités.</p>
</form>

<?php if ($participants): ?>
  <table class="dates-table">
    <thead><tr><th>Nom</th><th>Email</th><th></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($participants as $p): ?>
      <tr>
        <td><?= e($p['name'] !== '' ? $p['name'] : '—') ?></td>
        <td><?= e($p['email']) ?></td>
        <td><a href="/admin/polls/<?= e($poll['uuid']) ?>/participants/<?= (int)$p['id'] ?>" class="link small">Éditer</a></td>
        <td>
          <form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/participants/<?= (int)$p['id'] ?>/delete"
                onsubmit="return confirm('Supprimer ce participant et ses votes ?');">
            <?= csrf_field() ?>
            <button type="submit" class="danger small">Supprimer</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
