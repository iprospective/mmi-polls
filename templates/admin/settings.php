<?php
$active = 'settings';
require __DIR__ . '/_admin_nav.php';
?>

<form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>" class="card">
  <?= csrf_field() ?>
  <h2 style="margin-top:0;">Titre &amp; description</h2>
  <label>Titre <input type="text" name="title" value="<?= e($poll['title']) ?>" required></label>
  <label>Description
    <textarea name="description" data-wysiwyg style="display:none"><?= e($poll['description']) ?></textarea>
    <div class="wysiwyg-editor"></div>
  </label>
  <label>Date de clôture (facultatif)
    <input type="date" name="closed_at" value="<?= e($poll['closed_at'] ?? '') ?>">
  </label>
  <p class="muted small">Passé cette date, les participants ne peuvent plus modifier leurs disponibilités (vue verrouillée). Vide = sondage indéfiniment ouvert.</p>

  <fieldset class="check-group-wrap" style="margin-bottom: 0.85rem;">
    <legend>Visibilité des astreintes</legend>
    <label class="check-inline" style="font-size: 0.9rem;">
      <input type="checkbox" name="assignments_public" value="1" <?= !empty($poll['assignments_public']) ? 'checked' : '' ?>>
      Publier les astreintes (visibles côté participant)
    </label>
    <p class="muted small" style="margin: 0.4rem 0 0;">Décochez pour travailler en mode brouillon : les pictos P/S sur la grille publique, la section « Mes astreintes » sur la page personnelle et le flux iCal sont masqués jusqu'à ce que vous publiiez.</p>
  </fieldset>

  <h3>Trajet (facultatif)</h3>
  <p class="muted small">Renseigner ces deux points permet à l'algorithme de remplissage auto de privilégier les personnes les plus proches du point de départ.</p>

  <label>Adresse de départ (lieu de prise en charge)
    <input type="text" name="start_address" value="<?= e($poll['start_address'] ?? '') ?>" placeholder="ex. 12 rue Foo, Romans">
  </label>
  <?php if (!empty($poll['start_lat'])): ?>
    <div class="geo-found">
      <strong>📍 Localisée</strong><br>
      <span class="geo-display"><?= e($poll['start_geocoded'] ?? '') ?></span><br>
      <span class="muted small">coordonnées : <?= number_format((float)$poll['start_lat'], 5) ?>, <?= number_format((float)$poll['start_lng'], 5) ?></span>
    </div>
  <?php elseif (!empty($poll['start_address'])): ?>
    <div class="geo-failed"><strong>⚠️ Non géolocalisée</strong> — précisez ville/pays pour aider.</div>
  <?php endif; ?>

  <label>Adresse d'arrivée (destination)
    <input type="text" name="end_address" value="<?= e($poll['end_address'] ?? '') ?>" placeholder="ex. Maternité, Romans">
  </label>
  <?php if (!empty($poll['end_lat'])): ?>
    <div class="geo-found">
      <strong>📍 Localisée</strong><br>
      <span class="geo-display"><?= e($poll['end_geocoded'] ?? '') ?></span><br>
      <span class="muted small">coordonnées : <?= number_format((float)$poll['end_lat'], 5) ?>, <?= number_format((float)$poll['end_lng'], 5) ?></span>
    </div>
  <?php elseif (!empty($poll['end_address'])): ?>
    <div class="geo-failed"><strong>⚠️ Non géolocalisée</strong> — précisez ville/pays pour aider.</div>
  <?php endif; ?>

  <button type="submit">Enregistrer</button>
</form>

<form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/contact-email" class="card">
  <?= csrf_field() ?>
  <h2 style="margin-top:0;">Email de contact</h2>
  <p class="muted small">Adresse qui reçoit les signalements quand un·e
     participant·e conteste ses astreintes après notification.
     Laissez vide pour désactiver l'envoi (les signalements restent
     consultables dans l'onglet Astreintes).</p>
  <label>Email <input type="email" name="contact_email"
                      value="<?= e($poll['contact_email']) ?>"
                      placeholder="ex. moi@exemple.com"></label>
  <button type="submit">Enregistrer</button>
</form>

<div class="card">
  <h2 style="margin-top:0;">Managers</h2>
  <p class="muted small">Les managers ont accès complet à ce sondage (édition, astreintes, notifications) — au même titre que l'admin global. <?= is_admin() ? 'Vous pouvez en ajouter / retirer librement.' : 'Vous pouvez inviter d\'autres managers qui ont déjà un compte actif.' ?></p>

  <?php if (!$managers): ?>
    <p><em>Aucun manager — ce sondage est administré uniquement par l'admin global.</em></p>
  <?php else: ?>
    <div class="grid-wrap">
    <table class="dates-table">
      <thead>
        <tr><th>Manager</th><th>Ajouté</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($managers as $m):
          $name = $m['name'] !== '' ? $m['name'] : explode('@', $m['email'])[0];
        ?>
          <tr>
            <td>
              <strong><?= e($name) ?></strong>
              <span class="muted small">&lt;<?= e($m['email']) ?>&gt;</span>
              <?php if ($m['added_by_admin']): ?>
                <span class="owner-badge owner-admin">ajouté par l'admin</span>
              <?php elseif ($m['inviter_email']): ?>
                <span class="muted small">— invité·e par <?= e($m['inviter_name'] !== '' ? $m['inviter_name'] : $m['inviter_email']) ?></span>
              <?php endif; ?>
            </td>
            <td class="small"><?= e(date('d/m/Y', (int)$m['added_at'])) ?></td>
            <td>
              <form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/managers/<?= (int)$m['id'] ?>/delete" class="inline"
                    onsubmit="return confirm('Retirer <?= e(addslashes($name)) ?> de ce sondage ?');">
                <?= csrf_field() ?>
                <button type="submit" class="danger small">Retirer</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>

  <h3>Ajouter un manager</h3>
  <form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/managers">
    <?= csrf_field() ?>
    <div class="row">
      <input type="email" name="email"
             list="<?= is_admin() && $available_managers ? 'active-managers-list' : '' ?>"
             required placeholder="email@exemple.com">
      <button type="submit">Ajouter</button>
    </div>
    <?php if (is_admin() && $available_managers): ?>
      <datalist id="active-managers-list">
        <?php foreach ($available_managers as $am): ?>
          <option value="<?= e($am['email']) ?>"><?= e($am['name'] !== '' ? $am['name'] : $am['email']) ?></option>
        <?php endforeach; ?>
      </datalist>
      <p class="muted small">En tant qu'admin, les <?= count($available_managers) ?> managers actifs non encore présents sont proposés en autocomplétion ; tu peux aussi taper un autre email.</p>
    <?php else: ?>
      <p class="muted small">La personne doit avoir un compte manager actif (validé par l'admin global). Elle reçoit un email l'informant de son ajout.</p>
    <?php endif; ?>
  </form>
</div>

<form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/delete" class="card danger-zone"
      onsubmit="return confirm('Supprimer définitivement ce sondage ? Cette action est irréversible : votes, participants, astreintes, notifications seront perdus.');">
  <?= csrf_field() ?>
  <h2 style="margin-top:0;">Zone dangereuse</h2>
  <p class="muted small">
    La suppression du sondage entraîne la suppression en cascade de tous les
    votes, participants, créneaux, astreintes et notifications associés.
    Le lien public (<code>/p/<?= e($poll['uuid']) ?></code>) cessera de
    fonctionner. Action irréversible.
  </p>
  <button type="submit" class="danger">Supprimer ce sondage</button>
</form>
