<?php require_once __DIR__ . '/../../services/routing.php'; ?>

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
  <?php if (!empty($trip) && $trip['complete']):
    $legs_by = [];
    foreach ($trip['legs'] as $l) $legs_by[$l['name']] = $l;
    $backend = $legs_by['home_to_start']['backend'] ?? '';
  ?>
    <div class="card trip-recap">
      <h3 style="margin-top: 0;">Mon trajet pour une astreinte</h3>
      <p style="font-size: 1.05rem;">
        <strong>Total :</strong> <?= e(fmt_distance((int)$trip['total_m'])) ?>
        <?php if ($trip['total_s'] > 0): ?>
          <span class="muted">· environ <?= e(fmt_duration((int)$trip['total_s'])) ?> de route</span>
        <?php endif; ?>
        <?php if ($backend === 'haversine'): ?>
          <br><span class="muted small">⚠️ Estimation à vol d'oiseau (service de routes injoignable).</span>
        <?php endif; ?>
      </p>
      <ul class="trip-legs">
        <li>🏠 Chez vous → 🟢 Départ : <strong><?= e(fmt_distance((int)$legs_by['home_to_start']['distance_m'])) ?></strong>
          <?php if (($legs_by['home_to_start']['duration_s'] ?? 0) > 0): ?>
            <span class="muted small">(<?= e(fmt_duration((int)$legs_by['home_to_start']['duration_s'])) ?>)</span>
          <?php endif; ?>
        </li>
        <li>🟢 Départ → 🔴 Arrivée : <strong><?= e(fmt_distance((int)$legs_by['start_to_end']['distance_m'])) ?></strong>
          <?php if (($legs_by['start_to_end']['duration_s'] ?? 0) > 0): ?>
            <span class="muted small">(<?= e(fmt_duration((int)$legs_by['start_to_end']['duration_s'])) ?>)</span>
          <?php endif; ?>
        </li>
        <li>🔴 Arrivée → 🏠 Chez vous : <strong><?= e(fmt_distance((int)$legs_by['end_to_home']['distance_m'])) ?></strong>
          <?php if (($legs_by['end_to_home']['duration_s'] ?? 0) > 0): ?>
            <span class="muted small">(<?= e(fmt_duration((int)$legs_by['end_to_home']['duration_s'])) ?>)</span>
          <?php endif; ?>
        </li>
      </ul>
    </div>
  <?php endif; ?>

  <div id="map-data" style="display: none;"><?= htmlspecialchars(json_encode($markers, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?></div>
  <?php if (!empty($trips)): ?>
    <div id="trips-data" style="display: none;"><?= htmlspecialchars(json_encode($trips, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?></div>
  <?php endif; ?>
  <?php if (!empty($focus_icon_url)): ?>
    <div id="focus-icon-url" style="display: none;"><?= e($focus_icon_url) ?></div>
  <?php endif; ?>
  <div id="map"></div>
<?php endif; ?>
