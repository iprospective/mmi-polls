<?php
$status = $notif['status'];
$name   = $notif['participant_name'] !== '' ? $notif['participant_name'] : explode('@', $notif['participant_email'])[0];
?>

<h1>Vos astreintes</h1>
<p class="muted">
  Sondage : <a href="/p/<?= e($poll['uuid']) ?>"><?= e($poll['title']) ?></a><br>
  Pour <strong><?= e($name) ?></strong> &lt;<?= e($notif['participant_email']) ?>&gt;
</p>

<div class="card">
  <?php if (empty($assigns)): ?>
    <p>Aucun créneau ne vous est assigné pour le moment.</p>
  <?php else: ?>
    <h2 style="margin-top:0; border:none; padding:0;">Récapitulatif</h2>
    <ul class="assign-list">
      <?php foreach ($assigns as $a):
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
  <?php endif; ?>
</div>

<?php if ($status === 'confirmed'): ?>
  <div class="card status-confirmed">
    <h3 style="margin-top:0;">✓ Confirmé</h3>
    <p>Vous avez confirmé ces astreintes
       le <?= e(date('d/m/Y à H:i', (int)$notif['responded_at'])) ?>. Merci !</p>
    <p class="muted small">Si vous souhaitez revenir sur votre décision, utilisez les boutons ci-dessous.</p>
  </div>
<?php elseif ($status === 'contested'): ?>
  <div class="card status-contested">
    <h3 style="margin-top:0;">✗ Signalement transmis</h3>
    <p>Vous avez signalé un problème
       le <?= e(date('d/m/Y à H:i', (int)$notif['responded_at'])) ?>.</p>
    <?php if ($notif['reply']): ?>
      <p><strong>Votre message :</strong></p>
      <blockquote class="contest-reply"><?= nl2br(e($notif['reply'])) ?></blockquote>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="card">
  <h3 style="margin-top:0;">Votre réponse</h3>
  <p>Pouvez-vous confirmer que ces astreintes vous conviennent ?</p>

  <form method="post" action="/p/<?= e($poll['uuid']) ?>/confirm" class="confirm-actions">
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <input type="hidden" name="action" value="confirm">
    <button type="submit"<?= $status === 'confirmed' ? ' disabled' : '' ?>>✓ Je confirme</button>
  </form>

  <details class="contest-form">
    <summary>Je signale un problème</summary>
    <form method="post" action="/p/<?= e($poll['uuid']) ?>/confirm">
      <?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <input type="hidden" name="action" value="contest">
      <label>Message à l'organisateur·rice
        <textarea name="reply" rows="4" placeholder="Quel est le problème ? (créneau impossible, indisponibilité, etc.)" required><?= e($notif['reply']) ?></textarea>
      </label>
      <p class="muted small">Votre message sera envoyé par email à l'organisateur·rice.
        <?php if (!$poll['contact_email']): ?>
          <br><em>Attention : aucune adresse de contact n'est configurée pour ce sondage, le message sera juste enregistré.</em>
        <?php endif; ?>
      </p>
      <button type="submit" class="danger">Envoyer le signalement</button>
    </form>
  </details>
</div>

<p><a href="/p/<?= e($poll['uuid']) ?>">← Voir l'ensemble du sondage</a></p>
