<section class="card narrow">
  <h1>Přihlášení do administrace</h1>
  <?php if ($error): ?><div class="alert"><?= $e($error) ?></div><?php endif; ?>
  <form method="post">
    <div class="field"><label>E-mail<input type="email" name="email" autocomplete="username" required autofocus></label></div>
    <div class="field"><label>Heslo<input type="password" name="password" autocomplete="current-password" required></label></div>
    <button class="btn primary big">Přihlásit</button>
  </form>
</section>
