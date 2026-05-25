<h1>Connexion administrateur</h1>
<form method="post" action="/admin/login" class="card">
  <?= csrf_field() ?>
  <label>Identifiant
    <input type="text" name="username" autocomplete="username" autofocus required>
  </label>
  <label>Mot de passe
    <input type="password" name="password" autocomplete="current-password" required>
  </label>
  <button type="submit">Se connecter</button>
</form>
