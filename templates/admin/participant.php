<h1>Édition participant</h1>
<p class="muted">Sondage : <a href="/admin/polls/<?= e($poll['uuid']) ?>"><?= e($poll['title']) ?></a></p>

<form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/participants/<?= (int)$participant['id'] ?>" class="card">
  <?= csrf_field() ?>
  <div class="row">
    <label style="flex:1">Nom
      <input type="text" name="name" value="<?= e($participant['name']) ?>" placeholder="Prénom">
    </label>
    <label style="flex:2">Email
      <input type="email" name="email" value="<?= e($participant['email']) ?>" required>
    </label>
  </div>

  <?php if (!$dates): ?>
    <p><em>Aucune date dans ce sondage.</em></p>
  <?php else: ?>
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
  <?php endif; ?>

  <div class="row">
    <button type="submit">Enregistrer</button>
    <a href="/admin/polls/<?= e($poll['uuid']) ?>" class="link">Retour au sondage</a>
  </div>
</form>

<form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/participants/<?= (int)$participant['id'] ?>/delete" class="card"
      onsubmit="return confirm('Supprimer définitivement ce participant et tous ses votes ?');">
  <?= csrf_field() ?>
  <h3>Supprimer ce participant</h3>
  <button type="submit" class="danger">Supprimer</button>
</form>
