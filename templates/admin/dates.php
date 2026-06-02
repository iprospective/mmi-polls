<?php
$active = 'dates';
require __DIR__ . '/_admin_nav.php';
?>

<form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/dates" class="card">
  <?= csrf_field() ?>
  <h2 style="margin-top:0;">Ajouter une date</h2>
  <label>Date (AAAA-MM-JJ) <input type="date" name="day" required></label>
  <label>Créneaux (un par ligne)
    <textarea name="choices" rows="4" placeholder="Journée&#10;Soirée&#10;Nuit" required></textarea>
  </label>
  <button type="submit">Ajouter</button>
</form>

<details class="card">
  <summary><strong>Ajouter une plage de dates</strong> (création en masse)</summary>
  <form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/dates/bulk" style="margin-top: 0.85rem;">
    <?= csrf_field() ?>
    <div class="row" style="align-items: flex-start;">
      <label style="flex: 1; margin-bottom: 0;">Du
        <input type="date" name="date_from" required>
      </label>
      <label style="flex: 1; margin-bottom: 0;">Au
        <input type="date" name="date_to" required>
      </label>
    </div>

    <fieldset class="check-group-wrap">
      <legend>Jours de la semaine inclus</legend>
      <div class="check-group">
        <?php $days = [
          1 => 'Lun', 2 => 'Mar', 3 => 'Mer', 4 => 'Jeu',
          5 => 'Ven', 6 => 'Sam', 7 => 'Dim',
        ]; ?>
        <?php foreach ($days as $num => $label): ?>
          <label class="check-inline">
            <input type="checkbox" name="weekdays[]" value="<?= $num ?>" checked>
            <?= e($label) ?>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <label>Créneaux (un par ligne, appliqués à toutes les dates créées)
      <textarea name="choices" rows="4" placeholder="Journée&#10;Soirée&#10;Nuit" required></textarea>
    </label>

    <p class="muted small">Les dates déjà présentes sont ignorées (pas de doublon). Max 366 jours par opération.</p>
    <button type="submit">Créer la plage</button>
  </form>
</details>

<form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/dates/slot-hours" class="card">
  <?= csrf_field() ?>
  <h2 style="margin-top:0;">Horaires des créneaux</h2>
  <p class="muted small">Précisez les plages horaires de chaque libellé de créneau. Le chevauchement est volontaire&nbsp;: il donne au conducteur·rice une marge avant/après son créneau pour le trajet, évitant qu'un retard ne déborde sur le créneau de la personne suivante. <code>end &lt; start</code> = passage au lendemain (ex&nbsp;: Nuit 22:00 → 09:00).</p>
  <table class="dates-table">
    <thead>
      <tr><th>Libellé</th><th>Début</th><th>Fin</th></tr>
    </thead>
    <tbody>
    <?php foreach ($slot_labels as $lbl):
      $h = $current_hours[mb_strtolower($lbl)] ?? ['start' => '', 'end' => '']; ?>
      <tr>
        <td>
          <input type="text" name="label[]" value="<?= e($lbl) ?>" required>
        </td>
        <td>
          <input type="time" name="start[]" value="<?= e($h['start']) ?>" required>
        </td>
        <td>
          <input type="time" name="end[]" value="<?= e($h['end']) ?>" required>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted small">Pour ajouter un libellé qui n'est pas encore dans la liste, créez-le simplement comme nouveau créneau ci-dessous&nbsp;: il apparaîtra ici la prochaine fois.</p>
  <button type="submit">Enregistrer les horaires</button>
</form>

<h2>Dates &amp; créneaux existants</h2>

<?php if (!$dates): ?>
  <p><em>Aucune date pour l'instant.</em></p>
<?php else: ?>
  <div class="grid-wrap">
  <table class="dates-table">
    <thead>
      <tr><th>Jour</th><th>Créneaux</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($dates as $d): ?>
      <tr>
        <td><strong><?= e(fmt_day($d['day'])) ?></strong><br><span class="muted small"><?= e($d['day']) ?></span></td>
        <td>
          <ul class="choices">
            <?php foreach ($d['choices'] as $c): ?>
              <li>
                <span><?= e($c['label']) ?></span>
                <form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/choices/<?= (int)$c['id'] ?>/delete" class="inline"
                      onsubmit="return confirm('Supprimer ce créneau ?');">
                  <?= csrf_field() ?>
                  <button type="submit" class="link small">×</button>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
          <form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/dates/<?= (int)$d['id'] ?>/choices" class="row">
            <?= csrf_field() ?>
            <input type="text" name="label" placeholder="Nouveau créneau" required>
            <button type="submit">+</button>
          </form>
        </td>
        <td>
          <form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/dates/<?= (int)$d['id'] ?>/delete"
                onsubmit="return confirm('Supprimer cette date et tous ses créneaux ? Les votes et astreintes associés seront perdus.');">
            <?= csrf_field() ?>
            <button type="submit" class="danger small">Supprimer</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
