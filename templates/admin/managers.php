<h1>Comptes manager</h1>
<p class="muted"><a href="/admin">← Tous les sondages</a></p>

<?php
function _render_manager_row(array $m, string $section): string {
    ob_start();
    $name = $m['name'] !== '' ? $m['name'] : explode('@', $m['email'])[0];
?>
  <tr>
    <td><strong><?= e($name) ?></strong><br><span class="muted small"><?= e($m['email']) ?></span></td>
    <td class="small"><?= e(date('d/m/Y H:i', (int)$m['created_at'])) ?></td>
    <?php if ($section === 'rejected'): ?>
      <td class="small"><?= $m['rejection_reason'] ? e($m['rejection_reason']) : '<span class="muted">—</span>' ?></td>
    <?php elseif ($section === 'active'): ?>
      <td class="small"><?= $m['validated_at'] ? e(date('d/m/Y H:i', (int)$m['validated_at'])) : '—' ?></td>
    <?php endif; ?>
    <td>
      <?php if ($section === 'pending'): ?>
        <form method="post" action="/admin/managers/<?= (int)$m['id'] ?>/validate" class="inline"
              onsubmit="return confirm('Valider le compte de <?= e(addslashes($name)) ?> ?');">
          <?= csrf_field() ?>
          <button type="submit">Valider</button>
        </form>
        <details class="inline" style="display: inline-block;">
          <summary class="link small">Refuser</summary>
          <form method="post" action="/admin/managers/<?= (int)$m['id'] ?>/reject" style="margin-top: 0.5rem;">
            <?= csrf_field() ?>
            <label>Motif (facultatif)
              <input type="text" name="reason" placeholder="optionnel">
            </label>
            <button type="submit" class="danger small">Refuser définitivement</button>
          </form>
        </details>
      <?php else: ?>
        <form method="post" action="/admin/managers/<?= (int)$m['id'] ?>/delete" class="inline"
              onsubmit="return confirm('Supprimer définitivement le compte de <?= e(addslashes($name)) ?> ? Ses sondages perdront leur propriétaire (mais ne seront pas supprimés).');">
          <?= csrf_field() ?>
          <button type="submit" class="danger small">Supprimer</button>
        </form>
      <?php endif; ?>
    </td>
  </tr>
<?php
    return ob_get_clean();
}
?>

<h2>En attente de validation (<?= count($pending) ?>)</h2>
<?php if (!$pending): ?>
  <p><em>Aucune demande en attente.</em></p>
<?php else: ?>
  <table class="dates-table">
    <thead><tr><th>Manager</th><th>Inscription</th><th>Actions</th></tr></thead>
    <tbody>
      <?php foreach ($pending as $m) echo _render_manager_row($m, 'pending'); ?>
    </tbody>
  </table>
<?php endif; ?>

<h2>Actifs (<?= count($active) ?>)</h2>
<?php if (!$active): ?>
  <p><em>Aucun compte actif.</em></p>
<?php else: ?>
  <table class="dates-table">
    <thead><tr><th>Manager</th><th>Inscription</th><th>Validation</th><th>Actions</th></tr></thead>
    <tbody>
      <?php foreach ($active as $m) echo _render_manager_row($m, 'active'); ?>
    </tbody>
  </table>
<?php endif; ?>

<?php if ($rejected): ?>
  <h2>Refusés (<?= count($rejected) ?>)</h2>
  <table class="dates-table">
    <thead><tr><th>Manager</th><th>Inscription</th><th>Motif</th><th>Actions</th></tr></thead>
    <tbody>
      <?php foreach ($rejected as $m) echo _render_manager_row($m, 'rejected'); ?>
    </tbody>
  </table>
<?php endif; ?>
