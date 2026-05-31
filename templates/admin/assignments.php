<?php
// Astreintes : pour chaque créneau, sélection d'un principal + d'un suppléant
// parmi les participants ayant répondu "oui" ou "peut-être".
// Le libellé des options des <select> est régénéré côté JS à chaque changement
// pour montrer le compte courant (Pₓ / Sᵧ) par personne.

// Compte initial par participant (basé sur les valeurs sélectionnées en base).
$counts = [];
foreach ($participants as $p) {
    $counts[(int)$p['id']] = ['primary' => 0, 'backup' => 0];
}
foreach ($assigns as $cid => $roles) {
    foreach ($roles as $role => $pid) {
        if (!isset($counts[$pid])) $counts[$pid] = ['primary' => 0, 'backup' => 0];
        $counts[$pid][$role]++;
    }
}

// Map id -> nom (fallback sur la partie locale de l'email).
$name_of = [];
foreach ($participants as $p) {
    $name_of[(int)$p['id']] = $p['name'] !== '' ? $p['name'] : explode('@', $p['email'])[0];
}

$total_choices = 0;
foreach ($dates as $d) $total_choices += count($d['choices']);
?>

<h1>Astreintes</h1>
<p class="muted">
  Sondage : <a href="/admin/polls/<?= e($poll['uuid']) ?>"><?= e($poll['title']) ?></a>
  — <?= $total_choices ?> créneaux, <?= count($participants) ?> participants
</p>

<form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/assignments" id="assignments-form">
  <?= csrf_field() ?>

  <div class="grid-wrap">
  <table class="vote-grid assignments-grid">
    <thead>
      <tr>
        <th class="date-col">Date</th>
        <th class="slot-col">Créneau</th>
        <th>Récap</th>
        <th>Principal·e</th>
        <th>Suppléant·e</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($dates as $d):
      $rows = count($d['choices']);
      $first = true;
      foreach ($d['choices'] as $c):
        // Candidats : participants ayant voté "yes" ou "maybe" pour ce créneau.
        $cid = (int)$c['id'];
        $yes_ids = [];
        $maybe_ids = [];
        $n = ['yes' => 0, 'no' => 0, 'maybe' => 0];
        foreach ($participants as $p) {
            $v = $votes[$p['id']][$cid] ?? null;
            if ($v === 'yes')   { $yes_ids[]   = (int)$p['id']; $n['yes']++; }
            elseif ($v === 'maybe') { $maybe_ids[] = (int)$p['id']; $n['maybe']++; }
            elseif ($v === 'no') $n['no']++;
        }
        $sel_primary = $assigns[$cid]['primary'] ?? 0;
        $sel_backup  = $assigns[$cid]['backup']  ?? 0;
    ?>
      <tr<?= $first ? ' class="day-first"' : '' ?>>
        <?php if ($first): ?>
          <th class="date-cell" rowspan="<?= $rows ?>">
            <?= e(fmt_day($d['day'])) ?><br>
            <span class="muted small"><?= e($d['day']) ?></span>
          </th>
        <?php endif; $first = false; ?>
        <td class="slot-cell"><?= e($c['label']) ?></td>
        <td class="summary-cell small">
          <span class="v-yes"><?= $n['yes'] ?>✓</span>
          <span class="v-maybe"><?= $n['maybe'] ?>?</span>
          <span class="v-no"><?= $n['no'] ?>✗</span>
        </td>
        <?php foreach (['primary' => $sel_primary, 'backup' => $sel_backup] as $role => $sel): ?>
          <td>
            <select class="assign-select" data-role="<?= $role ?>"
                    data-prev="<?= (int)$sel ?>"
                    name="assignments[<?= $cid ?>][<?= $role ?>]">
              <option value="0" data-pid="0">—</option>
              <?php if (!empty($yes_ids)): ?>
                <optgroup label="A voté Oui">
                  <?php foreach ($yes_ids as $pid):
                      $n_p = $counts[$pid]['primary'];
                      $n_s = $counts[$pid]['backup'];
                  ?>
                    <option value="<?= $pid ?>"
                            data-pid="<?= $pid ?>"
                            data-name="<?= e($name_of[$pid]) ?>"
                            <?= $sel === $pid ? 'selected' : '' ?>>
                      <?= e($name_of[$pid]) ?> (<?= $n_p ?>P / <?= $n_s ?>S)
                    </option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endif; ?>
              <?php if (!empty($maybe_ids)): ?>
                <optgroup label="A voté Peut-être">
                  <?php foreach ($maybe_ids as $pid):
                      $n_p = $counts[$pid]['primary'];
                      $n_s = $counts[$pid]['backup'];
                  ?>
                    <option value="<?= $pid ?>"
                            data-pid="<?= $pid ?>"
                            data-name="<?= e($name_of[$pid]) ?>"
                            <?= $sel === $pid ? 'selected' : '' ?>>
                      <?= e($name_of[$pid]) ?> (<?= $n_p ?>P / <?= $n_s ?>S)
                    </option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endif; ?>
              <?php if (empty($yes_ids) && empty($maybe_ids)): ?>
                <option disabled>aucun candidat dispo</option>
              <?php endif; ?>
            </select>
          </td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; endforeach; ?>
    </tbody>
  </table>
  </div>

  <div class="assignments-bar">
    <button type="submit">Enregistrer les astreintes</button>
    <a href="/admin/polls/<?= e($poll['uuid']) ?>" class="link">Retour au sondage</a>
    <div class="muted small" id="assignments-totals"></div>
  </div>
</form>
