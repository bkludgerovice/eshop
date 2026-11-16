<section class="card narrow">
  <h1>První spuštění – hlavní administrátor</h1>
  <?php if ($error): ?><div class="alert"><?= $e($error) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="key" value="<?= $e($key) ?>">
    <div class="field"><label>Jméno a příjmení<input name="name" required></label></div>
    <div class="field"><label>E-mail<input type="email" name="email" required></label></div>
    <div class="field"><label>Heslo (alespoň 10 znaků)<input type="password" name="password" minlength="10" required autocomplete="new-password"></label></div>
    <button class="btn primary big">Založit účet</button>
  </form>
</section>
