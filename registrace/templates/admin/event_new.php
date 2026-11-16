<section class="card narrow">
<h1>Nová akce</h1>
<form method="post"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <div class="field"><label>Název akce<input name="name" required placeholder="Zimní běh Hlučín 2027"></label></div>
  <div class="field"><label>Datum a čas startu<input type="datetime-local" name="starts_at" required></label></div>
  <div class="field"><label>Adresa stránky (nepovinné)<input name="slug" placeholder="zimni-beh-2027"></label><small class="hint">Vytvoří se automaticky z názvu.</small></div>
  <button class="btn primary">Založit</button>
</form>
</section>
