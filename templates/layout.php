<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title ?? 'mmidate') ?></title>
<link rel="icon" type="image/png" href="<?= e(asset_url('/public/logo.png')) ?>">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;700&display=swap">
<link rel="stylesheet" href="<?= e(asset_url('/public/style.css')) ?>">
<?php if (!empty($include_editor)): ?>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css">
  <script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js" defer></script>
  <script src="<?= e(asset_url('/public/editor.js')) ?>" defer></script>
<?php endif; ?>
<?php if (!empty($include_assignments)): ?>
  <script src="<?= e(asset_url('/public/assignments.js')) ?>" defer></script>
<?php endif; ?>
<?php if (!empty($include_sortable)): ?>
  <script src="<?= e(asset_url('/public/sortable-table.js')) ?>" defer></script>
<?php endif; ?>
<?php if (!empty($include_contact_toggles)): ?>
  <script src="<?= e(asset_url('/public/contact-toggles.js')) ?>" defer></script>
<?php endif; ?>
</head>
<body>
<header class="topbar">
  <a href="/" class="brand">
    <img src="/public/logo.png" alt="" class="brand-logo">
    <span class="brand-mark"><span class="brand-prefix">MMI</span><span class="brand-name">date</span></span>
  </a>
  <nav>
    <?php if (is_admin()): ?>
      <a href="/admin">Sondages</a>
      <a href="/admin/managers">Managers</a>
      <form method="post" action="/admin/logout" class="inline">
        <?= csrf_field() ?>
        <button type="submit" class="link">Déconnexion admin</button>
      </form>
    <?php elseif (is_manager()): ?>
      <a href="/manager">Mes sondages</a>
      <span class="muted small">· <?= e($_SESSION['manager_email'] ?? '') ?></span>
      <form method="post" action="/logout" class="inline">
        <?= csrf_field() ?>
        <button type="submit" class="link">Déconnexion</button>
      </form>
    <?php else: ?>
      <a href="/login">Connexion</a>
      <a href="/admin/login" class="muted small">Admin</a>
    <?php endif; ?>
  </nav>
</header>
<main class="container">
  <?php foreach (($_flashes ?? []) as $f): ?>
    <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</main>
<footer class="footer">
  mmidate © <?= date('Y') ?> iProspective ·
  logiciel libre sous <a href="/LICENSE" rel="license">AGPL-3.0</a> ·
  <a href="/CHANGELOG.md">changelog</a>
</footer>
</body>
</html>
