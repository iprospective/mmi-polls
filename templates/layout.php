<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title ?? 'mmidate') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="/public/style.css">
</head>
<body>
<header class="topbar">
  <a href="/" class="brand">mmidate</a>
  <nav>
    <?php if (is_admin()): ?>
      <a href="/admin">Sondages</a>
      <form method="post" action="/admin/logout" class="inline">
        <?= csrf_field() ?>
        <button type="submit" class="link">Déconnexion admin</button>
      </form>
    <?php else: ?>
      <a href="/admin/login">Admin</a>
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
  <span>mmidate</span>
</footer>
</body>
</html>
