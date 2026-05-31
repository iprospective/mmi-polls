<?php
// Menu d'administration partagé entre les sous-pages d'un sondage.
// Chaque template hôte définit $active = 'home' | 'dates' | 'participants'
//   | 'assignments' | 'settings' avant l'include.
$tabs = [
    'home'         => ['Réponses',        '/admin/polls/' . $poll['uuid']],
    'dates'        => ['Dates & créneaux', '/admin/polls/' . $poll['uuid'] . '/dates'],
    'participants' => ['Participants',     '/admin/polls/' . $poll['uuid'] . '/participants'],
    'assignments'  => ['Astreintes',       '/admin/polls/' . $poll['uuid'] . '/assignments'],
    'calendar'     => ['Calendrier',       '/admin/polls/' . $poll['uuid'] . '/calendar'],
    'activity'     => ['Activité',         '/admin/polls/' . $poll['uuid'] . '/activity'],
    'settings'     => ['Paramètres',       '/admin/polls/' . $poll['uuid'] . '/settings'],
];
$active_tab = $active ?? 'home';
?>
<div class="admin-poll-header">
  <p class="muted small" style="margin:0 0 0.25rem;">
    <a href="/admin">← Tous les sondages</a>
  </p>
  <h1 style="margin:0;"><?= e($poll['title']) ?></h1>
</div>
<nav class="admin-tabs" aria-label="Sections du sondage">
  <?php foreach ($tabs as $key => [$label, $url]): ?>
    <a href="<?= e($url) ?>"<?= $key === $active_tab ? ' class="active" aria-current="page"' : '' ?>>
      <?= e($label) ?>
    </a>
  <?php endforeach; ?>
</nav>
