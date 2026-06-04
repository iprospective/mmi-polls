<h1><?= e($poll['title']) ?></h1>

<?php if (!empty($sent)): ?>
  <div class="card">
    <h2>Lien envoyé</h2>
    <p>Un lien de connexion a été envoyé à <strong><?= e($sent_to) ?></strong>.</p>
    <p>Vérifiez votre boîte mail (et les spams). Le lien est valable une heure.</p>
    <p class="muted small">Si l'email n'arrive pas, consultez <code>data/mail.log</code> côté serveur.</p>
    <p><a href="/p/<?= e($poll['uuid']) ?>">← Retour au sondage</a></p>
  </div>
<?php else: ?>

<div class="card">
  <h2 style="margin-top: 0;">Rejoindre le sondage</h2>
  <p>Indiquez votre email : nous vous enverrons un lien de connexion pour saisir ou modifier vos disponibilités.</p>
  <form method="post" action="/p/<?= e($poll['uuid']) ?>/login">
    <?= csrf_field() ?>
    <label>Email
      <input type="email" name="email" required autofocus>
    </label>
    <button type="submit">📩 M'envoyer le lien</button>
  </form>
  <p><a href="/p/<?= e($poll['uuid']) ?>">← Retour au sondage</a></p>
</div>

<details class="card login-admin">
  <summary><strong>Vous êtes organisateur·rice ?</strong> <span class="muted small">(manager ou admin global)</span></summary>

  <h3 style="margin-top: 1rem;">Manager — mot de passe</h3>
  <form method="post" action="/login">
    <?= csrf_field() ?>
    <label>Email
      <input type="email" name="email" required>
    </label>
    <label>Mot de passe
      <input type="password" name="password" required>
    </label>
    <button type="submit">Se connecter</button>
  </form>

  <h3>Manager — lien magique par email</h3>
  <p class="muted small">Pas de mot de passe à retenir : on vous envoie un lien à usage unique.</p>
  <form method="post" action="/login/magic">
    <?= csrf_field() ?>
    <label>Email
      <input type="email" name="email" required>
    </label>
    <button type="submit">M'envoyer le lien</button>
  </form>

  <p class="muted small" style="margin-top: 0.75rem;">
    Pas encore de compte manager ? <a href="/register">Créer un compte</a>.
  </p>

  <details style="margin-top: 1rem;">
    <summary class="muted small"><strong>Admin global</strong> de l'instance</summary>
    <form method="post" action="/admin/login" style="margin-top: 0.5rem;">
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
</details>

<?php endif; ?>
