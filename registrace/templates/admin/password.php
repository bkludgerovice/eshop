<section class="card narrow">
  <h1>Změna hesla</h1>
  <?php if ($error): ?><div class="alert"><?= $e($error) ?></div><?php endif; ?>
  <form method="post"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <div class="field"><label>Současné heslo<input type="password" name="old" required autocomplete="current-password"></label></div>
    <div class="field"><label>Nové heslo (alespoň 10 znaků)<input type="password" name="new" minlength="10" required autocomplete="new-password"></label></div>
    <div class="field"><label>Nové heslo znovu<input type="password" name="new2" minlength="10" required autocomplete="new-password"></label></div>
    <button class="btn primary">Změnit heslo</button>
  </form>
</section>
