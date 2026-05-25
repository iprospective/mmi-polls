<h1>Mes disponibilités</h1>
<p class="muted">Sondage : <a href="/p/<?= e($poll['uuid']) ?>"><?= e($poll['title']) ?></a>
   — connecté·e en tant que <strong><?= e($participant['email']) ?></strong></p>

<?php if (!$dates): ?>
  <p><em>Le sondage ne contient aucune date pour l'instant.</em></p>
<?php else: ?>
<form method="post" action="/p/<?= e($poll['uuid']) ?>/me" class="card">
  <?= csrf_field() ?>
  <label>Nom (affiché à côté de vos réponses)
    <input type="text" name="name" value="<?= e($participant['name']) ?>" placeholder="Votre prénom" required>
  </label>

  <div class="grid-wrap">
  <table class="vote-grid editable">
    <thead>
      <tr>
        <th>Date</th>
        <th>Créneau</th>
        <th>Oui</th>
        <th>Peut-être</th>
        <th>Non</th>
        <th>(vide)</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($dates as $d):
        $first = true;
        $rows = count($d['choices']);
        foreach ($d['choices'] as $c):
          $current = $myvotes[$c['id']] ?? '';
          $name = 'votes[' . (int)$c['id'] . ']';
      ?>
        <tr>
          <?php if ($first): ?>
            <th class="date-cell" rowspan="<?= $rows ?>"><?= e(fmt_day($d['day'])) ?><br><span class="muted small"><?= e($d['day']) ?></span></th>
          <?php endif; $first = false; ?>
          <td class="slot-cell"><?= e($c['label']) ?></td>
          <td class="v-yes"><label><input type="radio" name="<?= e($name) ?>" value="yes" <?= $current === 'yes' ? 'checked' : '' ?>></label></td>
          <td class="v-maybe"><label><input type="radio" name="<?= e($name) ?>" value="maybe" <?= $current === 'maybe' ? 'checked' : '' ?>></label></td>
          <td class="v-no"><label><input type="radio" name="<?= e($name) ?>" value="no" <?= $current === 'no' ? 'checked' : '' ?>></label></td>
          <td><label><input type="radio" name="<?= e($name) ?>" value="" <?= $current === '' ? 'checked' : '' ?>></label></td>
        </tr>
      <?php endforeach; endforeach; ?>
    </tbody>
  </table>
  </div>

  <div class="row">
    <button type="submit">Enregistrer</button>
    <a href="/p/<?= e($poll['uuid']) ?>" class="link">Retour au sondage</a>
  </div>
</form>

<form method="post" action="/p/<?= e($poll['uuid']) ?>/me/delete" class="card"
      onsubmit="return confirm('Supprimer toutes vos réponses ?');">
  <?= csrf_field() ?>
  <h3>Supprimer mes réponses</h3>
  <p class="muted small">Supprime votre nom et tous vos votes pour ce sondage.</p>
  <button type="submit" class="danger">Supprimer mes réponses</button>
</form>
<?php endif; ?>
