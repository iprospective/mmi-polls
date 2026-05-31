<h1>Mes disponibilités</h1>
<p class="muted">Sondage : <a href="/p/<?= e($poll['uuid']) ?>"><?= e($poll['title']) ?></a>
   — connecté·e en tant que <strong><?= e($participant['email']) ?></strong></p>

<?php if (!empty($my_assigns)): ?>
<div class="card">
  <h2 style="margin-top:0;">Mes astreintes (<?= count($my_assigns) ?>)</h2>
  <ul class="assign-list">
    <?php foreach ($my_assigns as $a):
      $is_primary = $a['role'] === 'primary';
    ?>
      <li>
        <span class="role-badge role-<?= e($a['role']) ?>"><?= $is_primary ? 'Principal·e' : 'Suppléant·e' ?></span>
        <strong><?= e(fmt_day($a['day'])) ?></strong>
        <span class="muted small"><?= e($a['day']) ?></span>
        — <?= e($a['label']) ?>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php if (!$dates): ?>
  <p><em>Le sondage ne contient aucune date pour l'instant.</em></p>
<?php else: ?>
<form method="post" action="/p/<?= e($poll['uuid']) ?>/me" class="card">
  <?= csrf_field() ?>
  <label>Nom (affiché à côté de vos réponses)
    <input type="text" name="name" value="<?= e($participant['name']) ?>" placeholder="Votre prénom" required>
  </label>

  <div class="row" style="align-items: flex-start;">
    <label style="flex: 2; margin-bottom: 0;">Téléphone (facultatif)
      <input type="tel" name="phone" value="<?= e($participant['phone'] ?? '') ?>" placeholder="ex. 06 12 34 56 78">
    </label>
    <label style="flex: 1; margin-bottom: 0;">Pour me contacter
      <select name="contact_method">
        <option value="">— (non précisé)</option>
        <?php foreach (contact_methods() as $key => $label):
          $cur = $participant['contact_method'] ?? ''; ?>
          <option value="<?= e($key) ?>" <?= $cur === $key ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>

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
