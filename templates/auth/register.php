<h1>Créer un compte manager</h1>
<p class="muted">Une fois votre compte créé, il devra être validé par l'administrateur·rice avant de pouvoir vous connecter. Vous recevrez un email de confirmation.</p>

<form method="post" action="/register" class="card">
  <?= csrf_field() ?>
  <label>Nom
    <input type="text" name="name" required autofocus>
  </label>
  <label>Email
    <input type="email" name="email" required>
  </label>
  <label>Mot de passe (8 caractères minimum)
    <input type="password" name="password" minlength="8" required>
  </label>
  <label>Confirmer le mot de passe
    <input type="password" name="password_confirm" minlength="8" required>
  </label>
  <button type="submit">Créer le compte</button>
</form>

<p><a href="/login" class="link">← Retour à la connexion</a></p>
