<?php
$active = 'map';
require __DIR__ . '/_admin_nav.php';

require_once __DIR__ . '/../../services/routing.php';

$n_participants = count(array_filter($markers, fn($m) => $m['kind'] === 'participant'));
$n_trips        = count($trips ?? []);

// Tri des trajets par distance totale croissante (les plus proches en haut)
$trips_sorted = $trips ?? [];
usort($trips_sorted, fn($a, $b) => $a['total_m'] <=> $b['total_m']);
?>

<p class="muted small">
  <?= $n_participants ?> participant·e·s géolocalisé·e·s ·
  Marqueur vert = départ, rouge = arrivée, coloré = participant·e.
  <?php if ($can_route): ?>
    <?= $n_trips ?> trajet·s calculé·s (chez-soi → départ → arrivée → chez-soi).
  <?php else: ?>
    <em>Renseignez l'adresse de départ ET d'arrivée du sondage dans Paramètres pour calculer les trajets.</em>
  <?php endif; ?>
</p>

<?php if (!$markers): ?>
  <p><em>Aucune position GPS connue pour ce sondage. Renseignez les adresses dans Paramètres et chez les participant·e·s.</em></p>
<?php else: ?>
  <?php if ($trips_sorted): ?>
    <div class="route-controls">
      <label class="check-inline">
        <input type="checkbox" id="toggle-routes" checked>
        Afficher les trajets sur la carte
      </label>
      <form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/map/recompute" class="inline"
            onsubmit="return confirm('Purger le cache des trajets et les recalculer via OSRM au prochain affichage ?');">
        <?= csrf_field() ?>
        <button type="submit" class="link">↺ Recalculer trajets</button>
      </form>
    </div>
  <?php endif; ?>

  <div id="map-data" style="display: none;"><?= htmlspecialchars(json_encode($markers, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?></div>
  <?php if ($trips_sorted): ?>
    <div id="trips-data" style="display: none;"><?= htmlspecialchars(json_encode($trips_sorted, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?></div>
  <?php endif; ?>
  <?php if (!empty($focus_icon_url)): ?>
    <div id="focus-icon-url" style="display: none;"><?= e($focus_icon_url) ?></div>
  <?php endif; ?>
  <div id="map"></div>

  <?php if ($trips_sorted): ?>
    <h3 style="margin-top: 1.5rem;">Trajets par distance totale</h3>
    <div class="grid-wrap">
    <table class="participants-table">
      <thead>
        <tr>
          <th></th>
          <th>Participant·e</th>
          <th class="num">Total</th>
          <th class="num">Durée</th>
          <th>Détail (chez → départ · départ → arrivée · arrivée → chez)</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($trips_sorted as $t):
          $legs_by = [];
          foreach ($t['legs'] as $l) $legs_by[$l['name']] = $l;
          $backend = $legs_by['home_to_start']['backend'] ?? '';
        ?>
          <tr>
            <td><span class="trip-swatch" style="background: <?= e($t['color']) ?>"></span></td>
            <td><strong><?= e($t['name']) ?></strong>
              <?php if ($backend === 'haversine'): ?>
                <br><span class="muted small" title="OSRM injoignable, distance estimée en vol d'oiseau">⚠️ vol d'oiseau</span>
              <?php endif; ?>
            </td>
            <td class="num"><strong><?= e(fmt_distance((int)$t['total_m'])) ?></strong></td>
            <td class="num"><?= e(fmt_duration((int)$t['total_s'])) ?></td>
            <td class="small muted">
              <?= e(fmt_distance((int)($legs_by['home_to_start']['distance_m'] ?? 0))) ?>
              · <?= e(fmt_distance((int)($legs_by['start_to_end']['distance_m'] ?? 0))) ?>
              · <?= e(fmt_distance((int)($legs_by['end_to_home']['distance_m'] ?? 0))) ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
