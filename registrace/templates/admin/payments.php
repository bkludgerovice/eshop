<?php use Bkl\Util; $ro = $canEdit ? '' : ' disabled'; ?>
<div class="row-between"><h1>Platby</h1>
<form method="post" action="<?= $e($adm('system')) ?>"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><fieldset<?= $ro ?>><button name="action" value="fio" class="btn secondary small">Stáhnout platby z Fio teď</button></fieldset></form></div>
<section class="card">
<h2>Nespárované platby (<?= count($unmatched) ?>)</h2>
<p class="hint">Platby s variabilním symbolem registrací (začíná 8), ke kterým se nenašla objednávka. Zadejte správný VS objednávky, nebo platbu označte jako nesouvisející. Ostatní platby na klubový účet (ples, e-shop…) systém ignoruje.</p>
<?php foreach ($unmatched as $p): ?>
<form method="post" class="inline-row"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>"><fieldset<?= $ro ?>>
  <b><?= $e($money((int) $p['amount'])) ?></b> · <?= $e(Util::fmtDate($p['received_at'])) ?> · VS <?= $e($p['vs']) ?> · <?= $e(trim($p['counter_name'] . ' ' . $p['counter_account'])) ?> · <?= $e($p['message']) ?>
  → VS objednávky <input name="vs" class="w10"> <button name="action" value="assign" class="btn small primary">Spárovat</button>
  <button name="action" value="ignore" class="btn small secondary">Nesouvisí</button></fieldset></form>
<?php endforeach; ?>
<?php if (!$unmatched): ?><p>Vše spárováno. 👍</p><?php endif; ?>
</section>
<section class="card"><h2>Poslední platby</h2>
<div class="tbl-wrap"><table class="tbl compact"><thead><tr><th>Datum</th><th>Zdroj</th><th class="r">Částka</th><th>VS</th><th>Objednávka</th><th>Od koho</th><th>Stav</th></tr></thead><tbody>
<?php foreach ($recent as $p): ?><tr><td><?= $e(Util::fmtDate($p['received_at'])) ?></td><td><?= $e($p['source']) ?></td><td class="r"><?= $e($money((int) $p['amount'])) ?></td><td><?= $e($p['vs']) ?></td>
<td><?= $p['order_id'] ? '<a href="' . $e($adm('objednavka/' . $p['order_id'])) . '">' . $e($p['order_vs'] . ' ' . $p['contact_name']) . '</a>' : '—' ?></td><td><?= $e($p['counter_name']) ?></td><td><?= $e($p['match_status']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
