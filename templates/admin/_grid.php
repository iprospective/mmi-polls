<?php
// Shared grid renderer.
// Expects: $dates, $participants, $votes (map participant_id => choice_id => value)
// Optionally: $assigns (map choice_id => ['primary' => pid, 'backup' => pid])
$symbols = ['yes' => '✓', 'no' => '✗', 'maybe' => '?'];
$hl = $GLOBALS['CONFIG']['highlight'] ?? ['yes_min' => 2, 'yesmaybe_min' => 2];
$yes_min      = (int)$hl['yes_min'];
$yesmaybe_min = (int)$hl['yesmaybe_min'];
$assigns_map  = $assigns ?? [];
$name_of = [];
foreach ($participants as $p) {
    $name_of[(int)$p['id']] = $p['name'] !== '' ? $p['name'] : explode('@', $p['email'])[0];
}
$has_any_assign = false;
foreach ($assigns_map as $by_role) if (!empty($by_role)) { $has_any_assign = true; break; }

// Filtre dates passées (masquées par défaut, toggle via ?show_past=1).
$today = date('Y-m-d');
$past_count = 0;
foreach ($dates as $d) if ($d['day'] < $today) $past_count++;
$show_past = !empty($_GET['show_past']);
if (!$show_past && $past_count > 0) {
    $dates = array_values(array_filter($dates, fn($d) => $d['day'] >= $today));
}
$_base = strtok($_SERVER['REQUEST_URI'], '?');
$_qs   = $_GET;
if ($show_past) { unset($_qs['show_past']); } else { $_qs['show_past'] = 1; }
$toggle_url = $_base . ($_qs ? '?' . http_build_query($_qs) : '');

// Stats par participant : nb Oui, nb Peut-être, nb assignations Principal·e
// et Suppléant·e. Affichées sous le nom dans l'entête de colonne.
$stats_of = [];
foreach ($participants as $p) {
    $pid = (int)$p['id'];
    $stats_of[$pid] = ['yes' => 0, 'maybe' => 0, 'primary' => 0, 'backup' => 0];
}
foreach ($votes as $pid => $by_choice) {
    if (!isset($stats_of[$pid])) continue;
    foreach ($by_choice as $val) {
        if ($val === 'yes')   $stats_of[$pid]['yes']++;
        elseif ($val === 'maybe') $stats_of[$pid]['maybe']++;
    }
}
foreach ($assigns_map as $by_role) {
    foreach ($by_role as $role => $pid) {
        if (!isset($stats_of[$pid])) continue;
        if ($role === 'primary') $stats_of[$pid]['primary']++;
        elseif ($role === 'backup') $stats_of[$pid]['backup']++;
    }
}

// Calcule en amont les comptes / statut par créneau et l'agrégat par jour.
// Le statut "jour" est le pire des statuts de ses créneaux
// (ok < warn < bad) → la date ne passe au vert que si TOUS ses créneaux le sont.
$row_counts = [];
$row_status = [];
$day_status = [];
$status_rank = ['ok' => 0, 'warn' => 1, 'bad' => 2];
foreach ($dates as $d) {
    $worst = null;
    foreach ($d['choices'] as $c) {
        $counts = ['yes' => 0, 'no' => 0, 'maybe' => 0];
        foreach ($participants as $p) {
            $v = $votes[$p['id']][$c['id']] ?? null;
            if ($v && isset($counts[$v])) $counts[$v]++;
        }
        if ($counts['yes'] >= $yes_min) {
            $st = 'ok';
        } elseif (($counts['yes'] + $counts['maybe']) >= $yesmaybe_min) {
            $st = 'warn';
        } else {
            $st = 'bad';
        }
        $row_counts[$c['id']] = $counts;
        $row_status[$c['id']] = $st;
        if ($worst === null || $status_rank[$st] > $status_rank[$worst]) {
            $worst = $st;
        }
    }
    $day_status[$d['id']] = $worst ?? 'bad';
}
?>
<?php if ($past_count > 0): ?>
<p class="past-toggle">
  <span>
    <?php if ($show_past): ?>
      Toutes les dates affichées, y compris <?= $past_count ?> passée<?= $past_count > 1 ? 's' : '' ?>.
    <?php else: ?>
      <?= $past_count ?> date<?= $past_count > 1 ? 's' : '' ?> passée<?= $past_count > 1 ? 's' : '' ?> masquée<?= $past_count > 1 ? 's' : '' ?>.
    <?php endif; ?>
  </span>
  <a href="<?= e($toggle_url) ?>"><?= $show_past ? 'Masquer les dates passées' : 'Tout afficher' ?></a>
</p>
<?php endif; ?>

<?php if ($has_any_assign): ?>
<p class="grid-legend muted small">
  <span class="role-marker rm-primary" aria-hidden="true">P</span> = personne d'astreinte principale ·
  <span class="role-marker rm-backup"  aria-hidden="true">S</span> = suppléant·e
</p>
<?php endif; ?>

<div class="grid-wrap">
<table class="vote-grid">
  <thead>
    <tr>
      <th class="date-col">Date</th>
      <th class="slot-col">Créneau</th>
      <?php foreach ($participants as $p):
        $full  = $p['name'] !== '' ? $p['name'] : explode('@', $p['email'])[0];
        $short = mb_substr($full, 0, 3);
        $st    = $stats_of[(int)$p['id']];
      ?>
        <th class="participant" title="<?= e($full) ?>">
          <div class="p-name"><?= e($short) ?>…</div>
          <div class="p-stats p-votes">
            <span class="v-yes">✓<?= $st['yes'] ?></span>
            <span class="v-maybe">?<?= $st['maybe'] ?></span>
          </div>
          <?php if ($has_any_assign): ?>
          <div class="p-stats p-assigns">
            <span class="m-p" title="Principal·e">P<?= $st['primary'] ?></span>
            <span class="m-s" title="Suppléant·e">S<?= $st['backup'] ?></span>
          </div>
          <?php endif; ?>
        </th>
      <?php endforeach; ?>
      <th class="summary-col">Récap</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($dates as $d):
      $rows   = count($d['choices']);
      $first  = true;
      $day_st = $day_status[$d['id']];
      foreach ($d['choices'] as $c):
        $status = $row_status[$c['id']];
        $counts = $row_counts[$c['id']];
    ?>
      <tr class="row-<?= $status ?><?= $first ? ' day-first' : '' ?>">
        <?php if ($first): ?>
          <th class="date-cell status-<?= $day_st ?>" rowspan="<?= $rows ?>">
            <?= e(fmt_day($d['day'])) ?><br>
            <span class="muted small"><?= e($d['day']) ?></span>
          </th>
        <?php endif; $first = false; ?>
        <td class="slot-cell"><?= e($c['label']) ?></td>
        <?php foreach ($participants as $p):
          $v = $votes[$p['id']][$c['id']] ?? null;
          $cls = $v ? 'v-' . $v : 'v-none';
          $sym = $v ? $symbols[$v] : '—';
          $assign_role = null;
          $cell_assigns = $assigns_map[(int)$c['id']] ?? [];
          if (($cell_assigns['primary'] ?? 0) === (int)$p['id'])      $assign_role = 'primary';
          elseif (($cell_assigns['backup'] ?? 0) === (int)$p['id'])   $assign_role = 'backup';
        ?>
          <td class="vote-cell <?= $cls ?><?= $assign_role ? ' is-' . $assign_role : '' ?>">
            <?php if ($assign_role): ?>
              <span class="role-marker rm-<?= $assign_role ?>"
                    title="<?= $assign_role === 'primary' ? 'Principal·e' : 'Suppléant·e' ?>"><?= $assign_role === 'primary' ? 'P' : 'S' ?></span>
            <?php endif; ?>
            <?= $sym ?>
          </td>
        <?php endforeach; ?>
        <td class="summary-cell status-<?= $status ?>">
          <span class="v-yes"><?= $counts['yes'] ?>✓</span>
          <span class="v-maybe"><?= $counts['maybe'] ?>?</span>
          <span class="v-no"><?= $counts['no'] ?>✗</span>
        </td>
      </tr>
    <?php endforeach; endforeach; ?>
  </tbody>
</table>
</div>

<?php
// Vue alternative pour mobile : un bloc par créneau, avec récap pliable.
// CSS toggles : .grid-wrap visible >700px, .mobile-grid visible <=700px.
?>
<ul class="mobile-grid">
<?php foreach ($dates as $d):
  $day_st = $day_status[$d['id']];
?>
  <li class="m-day-header status-<?= e($day_st) ?>">
    <strong><?= e(fmt_day($d['day'])) ?></strong>
    <span class="muted small"><?= e($d['day']) ?></span>
  </li>
  <?php foreach ($d['choices'] as $c):
    $cid = (int)$c['id'];
    $status = $row_status[$cid];
    $counts = $row_counts[$cid];
    // Regroupe les voteurs par valeur, pour la zone repliable.
    $by_value = ['yes' => [], 'maybe' => [], 'no' => []];
    foreach ($participants as $p) {
      $v = $votes[$p['id']][$cid] ?? null;
      if ($v && isset($by_value[$v])) {
        $by_value[$v][] = $p['name'] !== '' ? $p['name'] : explode('@', $p['email'])[0];
      }
    }
    $total_votes = $counts['yes'] + $counts['no'] + $counts['maybe'];
  ?>
  <?php
    $m_assigns = $assigns_map[(int)$c['id']] ?? [];
    $m_primary = isset($m_assigns['primary']) ? ($name_of[(int)$m_assigns['primary']] ?? null) : null;
    $m_backup  = isset($m_assigns['backup'])  ? ($name_of[(int)$m_assigns['backup']]  ?? null) : null;
  ?>
  <li class="m-slot row-<?= $status ?>">
    <div class="m-head">
      <span class="m-label"><?= e($c['label']) ?></span>
      <span class="m-recap">
        <span class="v-yes"><?= $counts['yes'] ?>✓</span>
        <span class="v-maybe"><?= $counts['maybe'] ?>?</span>
        <span class="v-no"><?= $counts['no'] ?>✗</span>
      </span>
    </div>
    <?php if ($m_primary || $m_backup): ?>
    <div class="m-assigns">
      <?php if ($m_primary): ?>
        <span class="m-assign m-assign-primary"><span class="role-marker rm-primary">P</span> <?= e($m_primary) ?></span>
      <?php endif; ?>
      <?php if ($m_backup): ?>
        <span class="m-assign m-assign-backup"><span class="role-marker rm-backup">S</span> <?= e($m_backup) ?></span>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($total_votes > 0): ?>
    <details class="m-detail">
      <summary>Qui ?</summary>
      <?php foreach (['yes' => '✓', 'maybe' => '?', 'no' => '✗'] as $val => $sym): ?>
        <?php if (!empty($by_value[$val])): ?>
          <div class="m-grp v-<?= $val ?>"><?= $sym ?> <?= e(implode(', ', $by_value[$val])) ?></div>
        <?php endif; ?>
      <?php endforeach; ?>
    </details>
    <?php endif; ?>
  </li>
  <?php endforeach; ?>
<?php endforeach; ?>
</ul>
