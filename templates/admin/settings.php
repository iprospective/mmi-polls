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
