<?php use Bkl\Util; $roles = ['hlavni_admin' => 'hlavní administrátor', 'spravce_akce' => 'správce akcí', 'jen_cteni' => 'jen pro čtení']; ?>
<h1>Uživatelé administrace</h1>
<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Jméno</th><th>E-mail</th><th>Role</th><th>Poslední přihlášení</th><th></th></tr></thead><tbody>
<?php foreach ($users as $u): ?><tr class="<?= $u['active'] ? '' : 'muted' ?>"><td><?= $e($u['name']) ?></td><td><?= $e($u['email']) ?></td><td><?= $e($roles[$u['role']] ?? $u['role']) ?></td><td><?= $e(Util::fmtDateTime($u['last_login_at'])) ?></td>
<td><form method="post" class="inline"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
<button name="action" value="reset" class="btn small secondary" data-confirm="Vygenerovat nové heslo?">Nové heslo</button> <button name="action" value="toggle" class="btn small secondary"><?= $u['active'] ? 'Zablokovat' : 'Odblokovat' ?></button></form></td></tr><?php endforeach; ?>
</tbody></table></div>
<section class="card"><h2>Nový uživatel</h2>
<form method="post" class="inline-row"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input name="name" placeholder="Jméno"> <input type="email" name="email" placeholder="e-mail">
  <select name="role"><?php foreach ($roles as $k => $v): ?><option value="<?= $k ?>" <?= $k === 'spravce_akce' ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
  <button name="action" value="add" class="btn small primary">Založit</button></form>
<p class="hint">Dočasné heslo se zobrazí po založení – předejte ho osobně nebo telefonem, ne e-mailem.</p></section>
