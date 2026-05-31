<?php
$active = 'activity';
require __DIR__ . '/_admin_nav.php';
?>

<p class="muted">Les <?= count($events) ?> derniers événements (limite 500). Du plus récent au plus ancien.</p>

<?php if (!$events): ?>
  <p><em>Aucun événement enregistré pour ce sondage.</em></p>
<?php else: ?>
<div class="grid-wrap">
<table class="dates-table activity-log">
  <thead>
    <tr><th>Quand</th><th>Qui</th><th>Action</th><th>Détail</th></tr>
  </thead>
  <tbody>
    <?php foreach ($events as $ev):
      $actor_cls = 'actor-' . htmlspecialchars($ev['actor_type'], ENT_QUOTES);
    ?>
      <tr>
        <td class="small" title="<?= e(date('Y-m-d H:i:s', (int)$ev['created_at'])) ?>">
          <?= e(date('d/m H:i', (int)$ev['created_at'])) ?>
        </td>
        <td><span class="actor-pill <?= e($actor_cls) ?>"><?= e($ev['actor_label']) ?></span></td>
        <td><?= e(action_label($ev['action'])) ?></td>
        <td class="small"><?= e($ev['target']) ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
