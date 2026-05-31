<?php
$name = $participant['name'] !== '' ? $participant['name'] : explode('@', $participant['email'])[0];
$pri = 0; $bak = 0;
foreach ($assigns as $a) if ($a['role'] === 'primary') $pri++; else $bak++;
?>

<?php $active = 'participants'; require __DIR__ . '/_admin_nav.php'; ?>

<h2 style="margin-top:1rem;">Calendrier de <?= e($name) ?></h2>
<p class="muted">
  &lt;<?= e($participant['email']) ?>&gt;
  · <a href="/admin/polls/<?= e($poll['uuid']) ?>/participants" class="link">← Liste des participants</a>
</p>

<div class="card stats-strip">
  <div><strong><?= (int)$vote_counts['yes_count'] ?></strong><span class="muted small">  votes Oui</span></div>
  <div><strong><?= (int)$vote_counts['maybe_count'] ?></strong><span class="muted small">  Peut-être</span></div>
  <div><strong><?= (int)$vote_counts['no_count'] ?></strong><span class="muted small">  Non</span></div>
  <div><strong><?= $pri ?></strong><span class="muted small">  Principal·e</span></div>
  <div><strong><?= $bak ?></strong><span class="muted small">  Suppléant·e</span></div>
  <?php if ($participant['votes_updated_at']): ?>
    <div class="muted small">Votes maj le <?= e(date('d/m/Y H:i', (int)$participant['votes_updated_at'])) ?></div>
  <?php endif; ?>
</div>

<div class="card">
  <?php if (empty($assigns)): ?>
    <p><em>Aucune astreinte assignée pour le moment.</em></p>
  <?php else: ?>
    <h2 style="margin-top:0; border:none; padding:0;">Astreintes (<?= count($assigns) ?>)</h2>
    <ul class="assign-list">
      <?php
        $prev_day = '';
        foreach ($assigns as $a):
          $is_new_day = $a['day'] !== $prev_day;
          $prev_day = $a['day'];
      ?>
        <li class="<?= $is_new_day ? 'day-first' : '' ?>">
          <span class="role-badge role-<?= e($a['role']) ?>">
            <?= $a['role'] === 'primary' ? 'Principal·e' : 'Suppléant·e' ?>
          </span>
          <strong><?= e(fmt_day($a['day'])) ?></strong>
          <span class="muted small"><?= e($a['day']) ?></span>
          — <?= e($a['label']) ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

<div class="card">
  <h3 style="margin-top:0;">Actions</h3>
  <p>
    <a href="/admin/polls/<?= e($poll['uuid']) ?>/participants/<?= (int)$participant['id'] ?>" class="btn">Éditer la personne</a>
    <a href="/admin/polls/<?= e($poll['uuid']) ?>/participants" class="btn btn-ghost">Liste des participants</a>
  </p>
  <?php if (!empty($assigns)): ?>
    <form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/assignments/notify"
          onsubmit="return confirm('Renvoyer la notification d\'astreintes à <?= e(addslashes($name)) ?> ?');">
      <?= csrf_field() ?>
      <input type="hidden" name="target" value="one">
      <input type="hidden" name="participant_id" value="<?= (int)$participant['id'] ?>">
      <label>Message personnalisé (facultatif)
        <textarea name="message" rows="2" placeholder="Mot personnel à ajouter en début d'email…"></textarea>
      </label>
      <button type="submit">Renvoyer la notification</button>
    </form>
  <?php endif; ?>
</div>
