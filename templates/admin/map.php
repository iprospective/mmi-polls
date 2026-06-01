<?php
$active = 'map';
require __DIR__ . '/_admin_nav.php';
?>

<p class="muted small">
  <?= count(array_filter($markers, fn($m) => $m['kind'] === 'participant')) ?> participant·e·s géolocalisé·e·s ·
  Marqueur vert = départ, rouge = arrivée, bleu = participant·e.
</p>

<?php if (!$markers): ?>
  <p><em>Aucune position GPS connue pour ce sondage. Renseignez les adresses dans Paramètres et chez les participant·e·s.</em></p>
<?php else: ?>
  <div id="map-data" style="display: none;"><?= htmlspecialchars(json_encode($markers, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?></div>
  <div id="map"></div>
<?php endif; ?>
