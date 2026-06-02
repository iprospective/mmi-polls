<?php
$active = 'map';
require __DIR__ . '/_admin_nav.php';

require_once __DIR__ . '/../../services/routing.php';

$n_participants = count(array_filter($markers, fn($m) => $m['kind'] === 'participant' || $m['kind'] === 'focus'));
$n_trips        = count($trips ?? []);

// Tri des trajets par distance totale croissante (les plus proches en haut)
$trips_sorted = $trips ?? [];
usort($trips_sorted, fn($a, $b) => $a['total_m'] <=> $b['total_m']);
?>

<p class="muted small">
  <?= $n_participants ?> participant·e·s géolocalisé·e·s ·
  Marqueur vert = départ, rouge = arrivée, coloré = participant·e<?php if (!empty($focus_icon_url) || array_filter($markers, fn($m) => $m['kind']==='focus')): ?>, rose = focus<?php endif; ?>.
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
      <label class="check-inline">
        Filtrer par participant·e :
        <select id="filter-participant">
          <option value="0">— Tou·te·s —</option>
          <?php foreach ($trips_sorted as $t): ?>
            <option value="<?= (int)$t['pid'] ?>"><?= e($t['name']) ?></option>
          <?php endforeach; ?>
        </select>
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
    <p class="muted small">Cliquez sur une ligne pour mettre en avant ce trajet sur la carte.</p>
    <div class="grid-wrap">
    <table class="trip-table">
      <colgroup>
        <col class="col-swatch">
        <col class="col-name">
        <col class="col-total">
        <col class="col-duration">
        <col class="col-leg">
        <col class="col-leg">
        <col class="col-leg">
      </colgroup>
      <thead>
        <tr>
          <th class="col-swatch"></th>
          <th class="col-name">Participant·e</th>
          <th class="col-total num">Total</th>
          <th class="col-duration num">Durée</th>
          <th class="col-leg num" title="chez → départ">🏠 → 🟢</th>
          <th class="col-leg num" title="départ → arrivée">🟢 → 🔴</th>
          <th class="col-leg num" title="arrivée → chez">🔴 → 🏠</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($trips_sorted as $t):
          $legs_by = [];
          foreach ($t['legs'] as $l) $legs_by[$l['name']] = $l;
          $backend = $legs_by['home_to_start']['backend'] ?? '';
        ?>
          <tr class="trip-row<?= !empty($t['is_focus']) ? ' trip-row-focus' : '' ?>" data-pid="<?= (int)$t['pid'] ?>">
            <td data-l="" class="col-swatch"><span class="trip-swatch" style="background: <?= e($t['color']) ?>"></span></td>
            <td data-l="Participant·e" class="col-name">
              <strong><?= e($t['name']) ?></strong>
              <?php if (!empty($t['is_focus'])): ?>
                <span class="trip-focus-badge" title="Participant·e mise en avant (paramètres du sondage)">🤰</span>
              <?php endif; ?>
              <?php if ($backend === 'haversine'): ?>
                <br><span class="muted small" title="OSRM injoignable, distance estimée en vol d'oiseau">⚠️ vol d'oiseau</span>
              <?php endif; ?>
            </td>
            <td data-l="Total" class="col-total num"><strong><?= e(fmt_distance((int)$t['total_m'])) ?></strong></td>
            <td data-l="Durée" class="col-duration num"><?= e(fmt_duration((int)$t['total_s'])) ?></td>
            <td data-l="chez → départ" class="col-leg num"><?= e(fmt_distance((int)($legs_by['home_to_start']['distance_m'] ?? 0))) ?></td>
            <td data-l="départ → arrivée" class="col-leg num"><?= e(fmt_distance((int)($legs_by['start_to_end']['distance_m'] ?? 0))) ?></td>
            <td data-l="arrivée → chez" class="col-leg num"><?= e(fmt_distance((int)($legs_by['end_to_home']['distance_m'] ?? 0))) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
