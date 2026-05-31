<?php
$active = 'dates';
require __DIR__ . '/_admin_nav.php';
?>

<form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/dates" class="card">
  <?= csrf_field() ?>
  <h2 style="margin-top:0;">Ajouter une date</h2>
  <label>Date (AAAA-MM-JJ) <input type="date" name="day" required></label>
  <label>Créneaux (un par ligne)
    <textarea name="choices" rows="4" placeholder="Journée&#10;Soirée&#10;Nuit" required></textarea>
  </label>
  <button type="submit">Ajouter</button>
</form>

<h2>Dates &amp; créneaux existants</h2>

<?php if (!$dates): ?>
  <p><em>Aucune date pour l'instant.</em></p>
<?php else: ?>
  <div class="grid-wrap">
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
                onsubmit="return confirm('Supprimer cette date et tous ses créneaux ? Les votes et astreintes associés seront perdus.');">
            <?= csrf_field() ?>
            <button type="submit" class="danger small">Supprimer</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
