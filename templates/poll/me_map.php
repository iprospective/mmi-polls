<h1>Ma carte</h1>
<p class="muted">
  Sondage : <a href="/p/<?= e($poll['uuid']) ?>"><?= e($poll['title']) ?></a>
  · <a href="/p/<?= e($poll['uuid']) ?>/me" class="link">← Mes disponibilités</a>
</p>

<p class="muted small">
  Marqueur vert = départ du trajet, rouge = arrivée, bleu = vous.
  Les positions des autres participant·e·s ne sont pas montrées ici.
</p>

<?php if (!$markers): ?>
  <p><em>Aucune position GPS connue. Ajoutez une adresse depuis la page « Mes disponibilités ».</em></p>
<?php else: ?>
  <div id="map-data" style="display: none;"><?= htmlspecialchars(json_encode($markers, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?></div>
  <div id="map"></div>
<?php endif; ?>
