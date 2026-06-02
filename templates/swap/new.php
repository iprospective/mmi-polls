<?php
$role_label = $role === 'primary' ? 'Principal·e' : 'Suppléant·e';
?>

<h1>Demander un remplacement</h1>
<p class="muted">
  Sondage : <a href="/p/<?= e($poll['uuid']) ?>"><?= e($poll['title']) ?></a>
  — <a href="/p/<?= e($poll['uuid']) ?>/me">← Mes disponibilités</a>
</p>

<div class="card">
  <h2 style="margin-top: 0;">Créneau à céder</h2>
  <p>
    <strong><?= e(fmt_day($slot_day)) ?></strong>
    <span class="muted small"><?= e($slot_day) ?></span>
    — <?= e($slot_label) ?>
    <span class="role-badge role-<?= e($role) ?>"><?= e($role_label) ?></span>
  </p>
  <p class="muted small">
    💡 Premier·ère qui clique « J'accepte » reprend votre astreinte. Vous serez prévenu·e par email.
    Si personne ne répond, vous pouvez annuler depuis « Mes disponibilités ».
  </p>
</div>

<form method="post" action="/p/<?= e($poll['uuid']) ?>/swap/new" class="card">
  <?= csrf_field() ?>
  <input type="hidden" name="cid"  value="<?= (int)$cid ?>">
  <input type="hidden" name="role" value="<?= e($role) ?>">

  <label>Message (facultatif)
    <textarea name="message" rows="3" placeholder="Une petite phrase pour expliquer pourquoi vous cherchez quelqu'un… (facultatif)"></textarea>
  </label>

  <fieldset class="check-group-wrap">
    <legend>Destinataires (<?= count($candidates) ?> candidat·e·s)</legend>
    <p class="muted small" style="margin: 0 0 0.5rem;">
      Pré-cochées : toutes les personnes qui avaient indiqué <strong>Oui</strong> ou <strong>Peut-être</strong> sur ce créneau.
      Décochez celles que vous ne voulez pas solliciter.
    </p>
    <div class="check-group swap-targets">
      <?php foreach ($candidates as $c):
        $cname = $c['name'] !== '' ? $c['name'] : explode('@', $c['email'])[0];
        $vote  = $c['my_vote'] ?: '';
      ?>
        <label class="check-inline">
          <input type="checkbox" name="targets[]" value="<?= (int)$c['id'] ?>" checked>
          <strong><?= e($cname) ?></strong>
          <?php if ($vote === 'yes'): ?>
            <span class="v-yes small">Oui</span>
          <?php elseif ($vote === 'maybe'): ?>
            <span class="v-maybe small">Peut-être</span>
          <?php endif; ?>
        </label>
      <?php endforeach; ?>
    </div>
  </fieldset>

  <div class="row">
    <button type="submit">📨 Envoyer la demande</button>
    <a href="/p/<?= e($poll['uuid']) ?>/me" class="link">Annuler</a>
  </div>
</form>
