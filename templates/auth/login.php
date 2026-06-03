<h1>Connexion</h1>

<?php if (!empty($sent)): ?>
  <div class="card">
    <h2 style="margin-top:0;">Lien envoyé</h2>
    <p>Si un compte actif existe pour <strong><?= e($sent_to) ?></strong>, un lien de connexion vient de lui être envoyé.</p>
    <p>Vérifiez votre boîte mail (et les spams). Le lien est valable une heure.</p>
    <p><a href="/login">← Retour</a></p>
  </div>
<?php else: ?>

<div class="card login-hint">
  <strong>⚠️ Vous voulez répondre à un sondage ?</strong>
  Cette page est destinée aux organisateur·rice·s. Pour donner vos disponibilités, utilisez le <strong>lien du sondage</strong> qui vous a été partagé (de la forme <code>/p/&lt;identifiant&gt;</code>).
</div>

<div class="card">
  <h2 style="margin-top:0;">Manager — mot de passe</h2>
  <form method="post" action="/login">
    <?= csrf_field() ?>
    <label>Email
      <input type="email" name="email" required autofocus>
    </label>
    <label>Mot de passe
      <input type="password" name="password" required>
    </label>
    <button type="submit">Se connecter</button>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0;">Manager — lien magique par email</h2>
  <p class="muted small">Pas de mot de passe à retenir : on vous envoie un lien à usage unique.</p>
  <form method="post" action="/login/magic">
    <?= csrf_field() ?>
    <label>Email
      <input type="email" name="email" required>
    </label>
    <button type="submit">M'envoyer le lien</button>
  </form>
</div>

<p class="muted small">
  Pas encore de compte manager ? <a href="/register">Créer un compte</a>.
</p>

<details class="card login-admin">
  <summary><strong>Connexion administrateur·rice global·e</strong></summary>
  <p class="muted small">Réservé à l'administrateur·rice de l'instance (identifiants en config serveur).</p>
  <form method="post" action="/admin/login">
    <?= csrf_field() ?>
    <label>Identifiant
      <input type="text" name="username" autocomplete="username" required>
    </label>
    <label>Mot de passe
      <input type="password" name="password" autocomplete="current-password" required>
    </label>
    <button type="submit">Se connecter en admin</button>
  </form>
</details>

<?php endif; ?>
