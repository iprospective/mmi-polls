<h1>Mes disponibilités</h1>
<p class="muted">Sondage : <a href="/p/<?= e($poll['uuid']) ?>"><?= e($poll['title']) ?></a>
   — connecté·e en tant que <strong><?= e($participant['email']) ?></strong></p>

<?php $closed = poll_is_closed($poll); ?>
<?php if ($closed): ?>
  <div class="card status-confirmed" style="border-left-color: var(--maybe);">
    <strong>🔒 Sondage clos</strong> depuis le <?= e(date('d/m/Y', strtotime($poll['closed_at']))) ?>.
    Vous pouvez consulter vos réponses mais plus les modifier.
  </div>
<?php elseif ($poll['closed_at']): ?>
  <p class="muted small">⏰ Sondage ouvert jusqu'au <?= e(date('d/m/Y', strtotime($poll['closed_at']))) ?> inclus.</p>
<?php endif; ?>

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
  <p style="margin-top: 1rem;">
    <a href="/p/<?= e($poll['uuid']) ?>/me/calendar" class="btn btn-ghost">📅 Voir mon calendrier mensuel</a>
  </p>

  <?php if (!empty($ical_token)):
    $ical_url = rtrim($GLOBALS['CONFIG']['app_url'], '/') . '/ical/' . $ical_token . '.ics';
  ?>
    <details style="margin-top: 1rem;">
      <summary class="link">📅 S'abonner depuis son agenda (Google Calendar, Apple, Thunderbird…)</summary>
      <p class="muted small" style="margin-top: 0.5rem;">Copiez ce lien et collez-le dans votre agenda en mode <em>« Abonnement / S'abonner à un calendrier »</em>. Les nouvelles astreintes apparaîtront automatiquement.</p>
      <code style="display:block; padding:0.5rem; background:#f8fafc; border-radius:6px; word-break:break-all; font-size:0.85rem;"><?= e($ical_url) ?></code>
      <p class="muted small" style="margin-top: 0.35rem;">
        <a href="<?= e($ical_url) ?>" download>Télécharger le .ics en une fois</a>
      </p>
    </details>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$dates): ?>
  <p><em>Le sondage ne contient aucune date pour l'instant.</em></p>
<?php else: ?>
<form method="post" action="/p/<?= e($poll['uuid']) ?>/me" class="card">
  <?= csrf_field() ?>
  <?php if ($closed): ?>
    <fieldset disabled style="border: none; padding: 0; margin: 0;">
  <?php endif; ?>
  <label>Nom (affiché à côté de vos réponses)
    <input type="text" name="name" value="<?= e($participant['name']) ?>" placeholder="Votre prénom" required>
  </label>

  <label>Téléphone (facultatif)
    <input type="tel" name="phone" value="<?= e($participant['phone'] ?? '') ?>" placeholder="ex. 06 12 34 56 78">
  </label>

  <?php if (poll_addresses_enabled($poll)): ?>
  <label>Adresse / ville (facultatif)
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
      <strong>⚠️ Non géolocalisée</strong> — adresse enregistrée mais Nominatim n'a rien trouvé. Précisez la ville ou le pays pour aider.
    </div>
  <?php else: ?>
    <p class="muted small">Permet de privilégier les personnes les plus proches du lieu de départ pour les astreintes de transport.</p>
  <?php endif; ?>
  <?php endif; ?>

  <fieldset class="check-group-wrap">
    <legend>Pour me contacter (plusieurs possibles)</legend>
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
    <button type="submit"<?= $closed ? ' disabled' : '' ?>>Enregistrer</button>
    <a href="/p/<?= e($poll['uuid']) ?>" class="link">Retour au sondage</a>
  </div>
  <?php if ($closed): ?></fieldset><?php endif; ?>
</form>

<form method="post" action="/p/<?= e($poll['uuid']) ?>/me/delete" class="card"
      onsubmit="return confirm('Supprimer toutes vos réponses ?');">
  <?= csrf_field() ?>
  <h3>Supprimer mes réponses</h3>
  <p class="muted small">Supprime votre nom et tous vos votes pour ce sondage.</p>
  <button type="submit" class="danger">Supprimer mes réponses</button>
</form>
<?php endif; ?>
