<?php
function _tier_label(float $usage): array {
    if ($usage < 0.50) return ['Prioritaire',  'tier-0'];
    if ($usage < 0.75) return ['Modéré',       'tier-1'];
    if ($usage < 0.90) return ['Fortement sollicité', 'tier-2'];
    return                    ['Saturé',       'tier-3'];
}
function _icon(string $name): string {
    $svgs = [
        'calendar' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
        'edit'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
        'mail'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
        'eye'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>',
        'eye-off'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>',
    ];
    return $svgs[$name] ?? '';
}
function _fmt_rel_date(?int $ts): string {
    if (!$ts) return '—';
    $delta = time() - $ts;
    if ($delta < 60) return 'à l\'instant';
    if ($delta < 3600) return floor($delta / 60) . ' min';
    if ($delta < 86400) return floor($delta / 3600) . ' h';
    if ($delta < 86400 * 7) return floor($delta / 86400) . ' j';
    return date('d/m/Y', $ts);
}

// Sépare les participants orphelins (zéro vote) du reste pour affichage.
$orphans = [];
$active_rows = [];
foreach ($rows as $r) {
    $total_votes = (int)$r['yes_count'] + (int)$r['maybe_count'] + (int)$r['no_count'];
    if ($total_votes === 0) $orphans[] = $r; else $active_rows[] = $r;
}
$sum = ['yes' => 0, 'maybe' => 0, 'no' => 0, 'none' => 0, 'p' => 0, 'b' => 0];
?>

<?php $active = 'participants'; require __DIR__ . '/_admin_nav.php'; ?>

<p class="muted">
  <?= count($rows) ?> personnes (<?= count($active_rows) ?> actives, <?= count($orphans) ?> orphelines),
  <?= $total_choices ?> créneaux au total. Cliquez sur un en-tête pour trier.
</p>

<form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/participants" class="card">
  <?= csrf_field() ?>
  <strong>Ajouter un participant</strong>
  <div class="row">
    <input type="text"  name="name"  placeholder="Nom (facultatif)">
    <input type="email" name="email" placeholder="email@exemple.com" required>
    <button type="submit">Ajouter</button>
  </div>
  <p class="muted small">Après création, tu pourras saisir ses disponibilités.</p>
</form>

<?php if (!$active_rows && !$orphans): ?>
  <p><em>Aucun participant pour l'instant.</em></p>
<?php else: ?>

<?php
$render_row = function (array $r, bool $is_orphan = false) use ($poll, $total_choices, &$sum) {
    $yes   = (int)$r['yes_count'];
    $maybe = (int)$r['maybe_count'];
    $no    = (int)$r['no_count'];
    $none  = max(0, $total_choices - $yes - $maybe - $no);
    $pri   = (int)$r['primary_count'];
    $bak   = (int)$r['backup_count'];
    $total = $pri + $bak;
    $cap   = $yes + 0.5 * $maybe;
    $usage = $cap > 0 ? $total / $cap : 0.0;
    [$tier_label, $tier_cls] = _tier_label($usage);
    $name  = $r['name'] !== '' ? $r['name'] : explode('@', $r['email'])[0];
    $vupd  = (int)($r['votes_updated_at'] ?? 0);
    $sum['yes']   += $yes;   $sum['maybe'] += $maybe; $sum['no'] += $no;
    $sum['none']  += $none;  $sum['p']     += $pri;   $sum['b']  += $bak;
?>
    <?php
      $cms = parse_contact_methods($r['contact_method'] ?? '');
      $tooltip_lines = [$r['email']];
      if ($r['phone']) $tooltip_lines[] = $r['phone'];
      if ($cms) {
          $labels = array_map('contact_method_label', $cms);
          $tooltip_lines[] = 'Préfère ' . implode(' / ', $labels);
      }
    ?>
    <?php $is_hidden = !empty($r['hidden_in_public']); ?>
    <tr class="<?= trim(($is_orphan ? 'orphan ' : '') . ($is_hidden ? 'hidden-public' : '')) ?>">
      <td data-l="Nom" data-sort-value="<?= e(mb_strtolower($name)) ?>" title="<?= e(implode("\n", $tooltip_lines)) ?>">
        <strong><?= e($name) ?></strong>
        <?= contact_methods_pills($r['contact_method'] ?? '') ?>
        <?php if ($is_hidden): ?>
          <span class="hidden-pill" title="Masqué·e dans la vue publique">masqué·e</span>
        <?php endif; ?>
      </td>
      <td data-l="Email" class="email-cell muted small"><?= e($r['email']) ?></td>
      <td data-l="Téléphone" class="phone-cell muted small">
        <?= $r['phone'] ? e($r['phone']) : '—' ?>
      </td>
      <td data-l="Oui"       class="num v-yes-cell"><?= $yes ?></td>
      <td data-l="Peut-être" class="num v-maybe-cell"><?= $maybe ?></td>
      <td data-l="Non"       class="num v-no-cell"><?= $no ?></td>
      <td data-l="Sans rép." class="num muted"><?= $none ?></td>
      <td data-l="Principal·e" class="num"><?= $pri ?></td>
      <td data-l="Suppléant·e" class="num"><?= $bak ?></td>
      <td data-l="Total" class="num"><strong><?= $total ?></strong></td>
      <td data-l="Usage" class="usage-cell" data-sort-value="<?= (int)round($usage * 100) ?>">
        <?php if ($cap > 0): ?>
          <span class="usage-bar">
            <span class="usage-fill <?= e($tier_cls) ?>" style="width: <?= min(100, (int)round($usage*100)) ?>%"></span>
          </span>
          <span class="usage-pct <?= e($tier_cls) ?>"><?= (int)round($usage*100) ?>%</span>
          <br><span class="muted small"><?= e($tier_label) ?></span>
        <?php else: ?>
          <span class="muted small">—</span>
        <?php endif; ?>
      </td>
      <td data-l="Modif. votes" class="small" data-sort-value="<?= (int)$vupd ?>">
        <?php if ($vupd): ?>
          <span title="<?= e(date('d/m/Y H:i', $vupd)) ?>"><?= e(_fmt_rel_date($vupd)) ?></span>
        <?php else: ?>
          <span class="muted">—</span>
        <?php endif; ?>
      </td>
      <td data-l="Notif" data-sort-value="<?= e($r['notif_status'] ?? '') ?>">
        <?php if ($r['notif_status']): ?>
          <span class="notif-badge status-<?= e($r['notif_status']) ?>-row">
            <?= ['sent' => 'Envoyé', 'confirmed' => 'Confirmé', 'contested' => 'Signalement'][$r['notif_status']] ?? e($r['notif_status']) ?>
          </span>
        <?php else: ?>
          <span class="muted small">—</span>
        <?php endif; ?>
      </td>
      <td data-l="Actions" class="actions-cell">
        <a href="/admin/polls/<?= e($poll['uuid']) ?>/participants/<?= (int)$r['id'] ?>/calendar"
           class="icon-btn" title="Voir le calendrier d'astreintes" aria-label="Calendrier">
          <?= _icon('calendar') ?>
        </a>
        <a href="/admin/polls/<?= e($poll['uuid']) ?>/participants/<?= (int)$r['id'] ?>"
           class="icon-btn" title="Éditer" aria-label="Éditer">
          <?= _icon('edit') ?>
        </a>
        <form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/participants/<?= (int)$r['id'] ?>/toggle-visibility" class="inline">
          <?= csrf_field() ?>
          <button type="submit" class="icon-btn<?= $is_hidden ? ' is-hidden' : '' ?>"
                  title="<?= $is_hidden ? 'Réafficher dans la vue publique' : 'Masquer dans la vue publique' ?>"
                  aria-label="<?= $is_hidden ? 'Réafficher' : 'Masquer' ?>">
            <?= $is_hidden ? _icon('eye-off') : _icon('eye') ?>
          </button>
        </form>
        <?php if ($total > 0): ?>
          <form method="post" action="/admin/polls/<?= e($poll['uuid']) ?>/assignments/notify" class="inline"
                onsubmit="return confirm('Renvoyer la notification d\'astreintes à <?= e(addslashes($name)) ?> ?');">
            <?= csrf_field() ?>
            <input type="hidden" name="target" value="one">
            <input type="hidden" name="participant_id" value="<?= (int)$r['id'] ?>">
            <button type="submit" class="icon-btn" title="Renvoyer la notification" aria-label="Renvoyer la notification">
              <?= _icon('mail') ?>
            </button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
<?php
};
?>

<div class="grid-wrap">
<table class="participants-table sortable">
  <thead>
    <tr>
      <th>Nom ↕</th>
      <th class="email-col">Email ↕</th>
      <th class="phone-col">Tél ↕</th>
      <th data-sort-type="num" title="Dates / créneaux où la personne a voté Oui"><span class="v-yes">Oui</span> ↕</th>
      <th data-sort-type="num" title="Peut-être"><span class="v-maybe">Peut-être</span> ↕</th>
      <th data-sort-type="num" title="Non"><span class="v-no">Non</span> ↕</th>
      <th data-sort-type="num" title="Pas de réponse">∅ ↕</th>
      <th data-sort-type="num" title="Astreintes principales attribuées">Principal·e ↕</th>
      <th data-sort-type="num" title="Astreintes suppléantes attribuées">Suppléant·e ↕</th>
      <th data-sort-type="num">Total ↕</th>
      <th data-sort-type="num" title="Total astreintes / capacité (Oui + ½ Peut-être)">Usage ↕</th>
      <th title="Dernière modification des votes">Maj votes ↕</th>
      <th>Notif ↕</th>
      <th class="no-sort">Actions</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($active_rows as $r) $render_row($r); ?>
  </tbody>
  <?php if ($active_rows): ?>
  <tfoot>
    <tr>
      <td colspan="3"><em>Totaux (<?= count($active_rows) ?> actives)</em></td>
      <td class="num"><?= $sum['yes'] ?></td>
      <td class="num"><?= $sum['maybe'] ?></td>
      <td class="num"><?= $sum['no'] ?></td>
      <td class="num"><?= $sum['none'] ?></td>
      <td class="num"><?= $sum['p'] ?></td>
      <td class="num"><?= $sum['b'] ?></td>
      <td class="num"><strong><?= $sum['p'] + $sum['b'] ?></strong></td>
      <td colspan="4" class="muted small">
        couverture principale&nbsp;: <?= $total_choices > 0 ? round($sum['p'] * 100 / $total_choices) : 0 ?>%
        — suppléante&nbsp;: <?= $total_choices > 0 ? round($sum['b'] * 100 / $total_choices) : 0 ?>%
      </td>
    </tr>
  </tfoot>
  <?php endif; ?>
</table>
</div>

<?php if ($orphans): ?>
  <h3>Personnes orphelines (n'ont pas répondu) — <?= count($orphans) ?></h3>
  <p class="muted small">Ces participants existent en base mais n'ont saisi aucune réponse.
     Tu peux leur renvoyer un magic-link (via l'admin du sondage) ou les supprimer.</p>
  <div class="grid-wrap">
  <table class="participants-table sortable">
    <thead>
      <tr>
        <th>Nom ↕</th>
        <th class="email-col">Email ↕</th>
        <th class="phone-col">Tél ↕</th>
        <th data-sort-type="num">Oui</th>
        <th data-sort-type="num">Peut-être</th>
        <th data-sort-type="num">Non</th>
        <th data-sort-type="num">Sans rép.</th>
        <th data-sort-type="num">Principal·e</th>
        <th data-sort-type="num">Suppléant·e</th>
        <th data-sort-type="num">Total</th>
        <th>Usage</th>
        <th>Maj votes ↕</th>
        <th>Notif</th>
        <th class="no-sort">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($orphans as $r) $render_row($r, true); ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>

<?php endif; ?>

<h2>Coordonnées</h2>
<p class="muted small">Référentiel de contact : email, téléphone, plateformes préférées.
   Cliquer sur une colonne plateforme pour grouper les personnes joignables sur ce canal.</p>

<div class="grid-wrap">
<table class="participants-table contacts-table sortable">
  <thead>
    <tr>
      <th>Nom ↕</th>
      <th>Email ↕</th>
      <th>Téléphone ↕</th>
      <?php foreach (contact_methods() as $key => $label): ?>
        <th data-sort-type="num"><?= e($label) ?> ↕</th>
      <?php endforeach; ?>
      <th class="no-sort">Actions</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($rows as $r):
      $name = $r['name'] !== '' ? $r['name'] : explode('@', $r['email'])[0];
      $cms  = parse_contact_methods($r['contact_method'] ?? '');
      $is_orphan_row = ((int)$r['yes_count'] + (int)$r['maybe_count'] + (int)$r['no_count']) === 0;
      $tel_href = $r['phone'] ? preg_replace('/[^0-9+]/', '', $r['phone']) : '';
    ?>
      <tr class="<?= $is_orphan_row ? 'orphan' : '' ?>">
        <td data-l="Nom" data-sort-value="<?= e(mb_strtolower($name)) ?>"><strong><?= e($name) ?></strong></td>
        <td data-l="Email"><a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a></td>
        <td data-l="Téléphone">
          <?php if ($r['phone']): ?>
            <a href="tel:<?= e($tel_href) ?>"><?= e($r['phone']) ?></a>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
        <?php foreach (contact_methods() as $key => $label):
          $has = in_array($key, $cms, true); ?>
          <td data-l="<?= e($label) ?>" class="check-col <?= $has ? 'cm-' . e($key) : '' ?>"
              data-sort-value="<?= $has ? 1 : 0 ?>">
            <?= $has ? '✓' : '<span class="muted">—</span>' ?>
          </td>
        <?php endforeach; ?>
        <td class="actions-cell">
          <a href="/admin/polls/<?= e($poll['uuid']) ?>/participants/<?= (int)$r['id'] ?>"
             class="icon-btn" title="Éditer">
            <?= _icon('edit') ?>
          </a>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>

<p><a href="/admin/polls/<?= e($poll['uuid']) ?>" class="link">← Retour au sondage</a></p>
