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
    <p>Indiquez votre email : nous vous enverrons un lien de connexion pour saisir ou modifier vos disponibilités.</p>
    <form method="post" action="/p/<?= e($poll['uuid']) ?>/login">
      <?= csrf_field() ?>
      <label>Email
        <input type="email" name="email" required autofocus>
      </label>
      <button type="submit">M'envoyer le lien</button>
    </form>
    <p><a href="/p/<?= e($poll['uuid']) ?>">← Retour au sondage</a></p>
  </div>
<?php endif; ?>
