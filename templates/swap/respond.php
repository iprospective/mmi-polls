<?php
$role_label = $req['role'] === 'primary' ? 'Principal·e' : 'Suppléant·e';
$r_name = $req['requester_name'] !== '' ? $req['requester_name'] : explode('@', $req['requester_email'])[0];
$target_name = $target['name'] ?? '';
if (!$target_name) {
    // recharge depuis swap_load_participant via la liste targets de $req
    foreach ($req['targets'] as $t) {
        if ((int)$t['id'] === (int)$target['id']) {
            $target_name = $t['name'] !== '' ? $t['name'] : explode('@', $t['email'])[0];
            $target_email = $t['email'];
            break;
        }
    }
}

$status = $req['status'];
$already_responded = $target['response'] !== null;
?>

<h1>Reprendre une astreinte ?</h1>
<p class="muted">
  Sondage : <a href="/p/<?= e($req['poll_uuid']) ?>"><?= e($req['poll_title']) ?></a><br>
  Pour <strong><?= e($target_name ?? '') ?></strong>
</p>

<div class="card">
  <h2 style="margin-top: 0;"><?= e($r_name) ?> cherche un·e remplaçant·e</h2>
  <p class="assign-list" style="font-size: 1.05rem;">
    <span class="role-badge role-<?= e($req['role']) ?>"><?= e($role_label) ?></span>
    <strong><?= e(fmt_day($req['day'])) ?></strong>
    <span class="muted small"><?= e($req['day']) ?></span>
    — <?= e($req['slot_label']) ?>
  </p>
  <?php if ($req['message']): ?>
    <p><strong>Message de <?= e($r_name) ?> :</strong></p>
    <blockquote class="contest-reply"><?= nl2br(e($req['message'])) ?></blockquote>
  <?php endif; ?>
</div>

<?php if ($status === 'taken'): ?>
  <div class="card status-confirmed">
    <h3 style="margin-top:0;">🔒 Déjà repris</h3>
    <p>Quelqu'un·e a accepté avant vous. Merci d'avoir pris le temps de regarder !</p>
  </div>
<?php elseif ($status === 'cancelled'): ?>
  <div class="card status-confirmed" style="border-left-color: var(--muted);">
    <h3 style="margin-top:0;">🚫 Demande annulée</h3>
    <p>La demande a été annulée par <?= e($r_name) ?> ou par l'organisateur·rice.</p>
  </div>
<?php elseif ($status === 'expired'): ?>
  <div class="card status-contested">
    <h3 style="margin-top:0;">⌛ Plus valable</h3>
    <p>L'astreinte d'origine a été modifiée entretemps par l'organisateur·rice. La demande n'a plus de sens.</p>
  </div>
<?php elseif ($already_responded && $target['response'] === 'decline'): ?>
  <div class="card status-confirmed" style="border-left-color: var(--maybe);">
    <h3 style="margin-top:0;">Vous avez décliné</h3>
    <p>Vous avez répondu non
       le <?= e(date('d/m/Y à H:i', (int)$target['responded_at'])) ?>.
       Vous pouvez changer d'avis tant que la demande est ouverte.</p>
  </div>
<?php elseif ($already_responded && $target['response'] === 'accept'): ?>
  <div class="card status-confirmed">
    <h3 style="margin-top:0;">✓ Vous avez accepté</h3>
    <p>Merci ! Vous avez repris cette astreinte
       le <?= e(date('d/m/Y à H:i', (int)$target['responded_at'])) ?>.</p>
  </div>
<?php endif; ?>

<?php if ($status === 'open'): ?>
<div class="card">
  <h3 style="margin-top: 0;">Votre réponse</h3>
  <p>Pouvez-vous prendre le relais sur ce créneau ?</p>

  <form method="post" action="/swap/<?= e($token) ?>" class="confirm-actions"
        onsubmit="return confirm('Confirmer la reprise de cette astreinte ? Votre planning sera mis à jour immédiatement.');">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="accept">
    <button type="submit">✓ J'accepte de reprendre</button>
  </form>

  <form method="post" action="/swap/<?= e($token) ?>" style="margin-top: 0.75rem;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="decline">
    <button type="submit" class="link">Je ne peux pas, désolé·e</button>
  </form>

  <p class="muted small" style="margin-top: 1rem;">
    Premier·ère qui clique gagne. Si quelqu'un·e a déjà accepté, vous le verrez en rechargeant la page.
  </p>
</div>
<?php endif; ?>

<p><a href="/p/<?= e($req['poll_uuid']) ?>">← Voir l'ensemble du sondage</a></p>
