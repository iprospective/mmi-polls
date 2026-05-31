<h1>Connexion manager</h1>

<?php if (!empty($sent)): ?>
  <div class="card">
    <h2 style="margin-top:0;">Lien envoyé</h2>
    <p>Si un compte actif existe pour <strong><?= e($sent_to) ?></strong>, un lien de connexion vient de lui être envoyé.</p>
    <p>Vérifiez votre boîte mail (et les spams). Le lien est valable une heure.</p>
    <p><a href="/login">← Retour</a></p>
  </div>
<?php else: ?>

<div class="card">
  <h2 style="margin-top:0;">Mot de passe</h2>
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
  <h2 style="margin-top:0;">Lien magique par email</h2>
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
  Pas encore de compte ? <a href="/register">Créer un compte manager</a>.
</p>

<?php endif; ?>
