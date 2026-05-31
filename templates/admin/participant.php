<?php $active = 'participants'; require __DIR__ . '/_admin_nav.php'; ?>

<h2 style="margin-top:1rem;">Édition participant</h2>
<p class="muted">
  <a href="/admin/polls/<?= e($poll['uuid']) ?>/participants" class="link">← Retour à la liste</a>
</p>

<form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/participants/<?= (int)$participant['id'] ?>" class="card">
  <?= csrf_field() ?>
  <div class="row" style="align-items: flex-start;">
    <label style="flex:1; margin-bottom: 0;">Nom
      <input type="text" name="name" value="<?= e($participant['name']) ?>" placeholder="Prénom">
    </label>
    <label style="flex:2; margin-bottom: 0;">Email
      <input type="email" name="email" value="<?= e($participant['email']) ?>" required>
    </label>
  </div>

  <label>Téléphone
    <input type="tel" name="phone" value="<?= e($participant['phone'] ?? '') ?>" placeholder="ex. 06 12 34 56 78">
  </label>

  <?php if (poll_addresses_enabled($poll)): ?>
  <label>Adresse / ville
    <input type="text" name="address" value="<?= e($participant['address'] ?? '') ?>" placeholder="ex. 12 rue de la Mairie, Romans">
  </label>
  <?php if (!empty($participant['latitude'])): ?>
    <div class="geo-found">
      <strong>📍 Adresse localisée</strong><br>
      <span class="geo-display"><?= e($participant['geocoded_address'] ?? '') ?></span><br>
      <span class="muted small">coordonnées : <?= number_format((float)$participant['latitude'], 5) ?>, <?= number_format((float)$participant['longitude'], 5) ?></span>
    </div>
  <?php elseif (!empty($participant['address'])): ?>
    <div class="geo-failed">
      <strong>⚠️ Non géolocalisée</strong> — adresse enregistrée mais Nominatim n'a rien trouvé.
    </div>
  <?php endif; ?>
  <?php endif; ?>

  <fieldset class="check-group-wrap">
    <legend>Moyens de contact préférés</legend>
    <div class="check-group">
      <?php $cur = parse_contact_methods($participant['contact_method'] ?? '');
            foreach (contact_methods() as $key => $label): ?>
        <label class="check-inline cm-<?= e($key) ?>">
          <input type="checkbox" name="contact_method[]" value="<?= e($key) ?>" <?= in_array($key, $cur, true) ? 'checked' : '' ?>>
          <?= e($label) ?>
        </label>
      <?php endforeach; ?>
    </div>
  </fieldset>

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
