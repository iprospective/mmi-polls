<?php
require_once __DIR__ . '/../../services/move_requests.php';

$is_src = $resp['role_in_swap'] === 'src';
$is_swap = !empty($req['dst_pid']);
$status  = $req['status'];

$me_name = $resp['name'] !== '' ? $resp['name'] : explode('@', $resp['email'])[0];
$src_name = $req['src_name'] !== '' ? $req['src_name'] : explode('@', $req['src_email'])[0];
$dst_name = $req['dst_name'] ? ($req['dst_name'] !== '' ? $req['dst_name'] : explode('@', $req['dst_email'] ?? '@', 2)[0]) : '';

$src_slot = move_human_slot($req['src_day'], $req['src_label'], $req['src_role']);
$dst_slot = move_human_slot($req['dst_day'], $req['dst_label'], $req['dst_role']);

$already = $resp['response'] !== null;
?>

<h1>Demande d'échange d'astreinte</h1>
<p class="muted">
  Sondage : <a href="/p/<?= e($req['poll_uuid']) ?>"><?= e($req['poll_title']) ?></a><br>
  Pour <strong><?= e($me_name) ?></strong> — initiée par <?= e($req['initiated_by']) ?>
</p>

<div class="card">
  <h2 style="margin-top: 0;">
    <?= $is_swap ? 'Proposition d\'échange' : 'Proposition de déplacement' ?>
  </h2>
  <?php if ($is_src): ?>
    <ul class="trip-legs">
      <li><strong>Actuellement vous tenez :</strong><br><?= e($src_slot) ?></li>
      <li><strong>Vous prendriez à la place :</strong><br><?= e($dst_slot) ?></li>
      <?php if ($is_swap): ?>
        <li class="muted small"><?= e($dst_name) ?> prendrait votre créneau actuel.</li>
      <?php endif; ?>
    </ul>
  <?php else: ?>
    <ul class="trip-legs">
      <li><strong>Actuellement vous tenez :</strong><br><?= e($dst_slot) ?></li>
      <li><strong>Vous prendriez à la place :</strong><br><?= e($src_slot) ?></li>
      <li class="muted small"><?= e($src_name) ?> prendrait votre créneau actuel.</li>
    </ul>
  <?php endif; ?>
  <?php if (!empty($req['message'])): ?>
    <p><strong>Message de l'organisateur·rice :</strong></p>
    <blockquote class="contest-reply"><?= nl2br(e($req['message'])) ?></blockquote>
  <?php endif; ?>
  <?php if ($is_swap): ?>
    <p class="muted small">⚠️ Le changement n'aura lieu QUE si les deux personnes acceptent.</p>
  <?php endif; ?>
</div>

<?php if ($status === 'applied'): ?>
  <div class="card status-confirmed">
    <h3 style="margin-top: 0;">✓ Appliqué</h3>
    <p>L'échange a bien été appliqué. Votre planning est à jour.</p>
  </div>
<?php elseif ($status === 'declined'): ?>
  <div class="card status-confirmed" style="border-left-color: var(--maybe);">
    <h3 style="margin-top: 0;">✗ Refusé</h3>
    <p>La demande a été refusée. Rien ne change.</p>
  </div>
<?php elseif ($status === 'cancelled'): ?>
  <div class="card status-confirmed" style="border-left-color: var(--muted);">
    <h3 style="margin-top: 0;">🚫 Annulée</h3>
    <p>L'organisateur·rice a annulé cette demande.</p>
  </div>
<?php elseif ($status === 'expired'): ?>
  <div class="card status-contested">
    <h3 style="margin-top: 0;">⌛ Plus valable</h3>
    <p>Les astreintes ont changé entre temps, la demande n'est plus applicable.</p>
  </div>
<?php elseif ($already && $resp['response'] === 'accept'): ?>
  <div class="card status-confirmed">
    <h3 style="margin-top: 0;">✓ Vous avez accepté</h3>
    <p>Merci ! <?php if ($is_swap): ?>On attend la réponse de l'autre personne avant d'appliquer.<?php endif; ?></p>
  </div>
<?php elseif ($already && $resp['response'] === 'decline'): ?>
  <div class="card status-confirmed" style="border-left-color: var(--maybe);">
    <h3 style="margin-top: 0;">Vous avez refusé</h3>
    <p>Refus enregistré.</p>
  </div>
<?php else: ?>
  <div class="card">
    <h3 style="margin-top: 0;">Votre réponse</h3>
    <form method="post" action="/move/<?= e($token) ?>" class="confirm-actions"
          onsubmit="return confirm('Accepter cet échange ?');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="accept">
      <button type="submit">✓ J'accepte</button>
    </form>
    <form method="post" action="/move/<?= e($token) ?>" style="margin-top: 0.75rem;"
          onsubmit="return confirm('Refuser cette proposition ? La demande sera clôturée pour tout le monde.');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="decline">
      <button type="submit" class="link danger">Je ne peux pas</button>
    </form>
  </div>
<?php endif; ?>

<p><a href="/p/<?= e($req['poll_uuid']) ?>">← Voir l'ensemble du sondage</a></p>
