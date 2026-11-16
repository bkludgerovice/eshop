<?php use Bkl\Admin\RegAdmin; use Bkl\Util; ?>
<div class="row-between"><h1>Registrace: <?= $e(Util::tr($ev['name'])) ?></h1>
<span><a class="btn secondary small" href="<?= $e($adm('akce/' . $ev['id'] . '/export')) ?>">Export do Excelu</a> <a class="btn secondary small" href="<?= $e($url($ev['slug'])) ?>" target="_blank">Nová registrace (formulář)</a></span></div>
<form method="get" class="filters adm-filters">
  <input type="search" name="q" value="<?= $e($f['q'] ?? '') ?>" placeholder="jméno, e-mail, klub, VS…">
  <select name="race"><option value="">všechny tratě</option><?php foreach ($ev['races'] as $r): ?><option value="<?= (int) $r['id'] ?>" <?= ($f['race'] ?? '') === (string) $r['id'] ? 'selected' : '' ?>><?= $e(Util::tr($r['name'])) ?></option><?php endforeach; ?></select>
  <select name="status"><option value="aktivni">aktivní (bez zrušených)</option><option value="vse" <?= ($f['status'] ?? '') === 'vse' ? 'selected' : '' ?>>všechny</option><?php foreach (RegAdmin::STATUSES as $k => $v): ?><option value="<?= $k ?>" <?= ($f['status'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
  <select name="pay"><option value="">platba: vše</option><?php foreach (RegAdmin::PAY as $k => $v): ?><option value="<?= $k ?>" <?= ($f['pay'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
  <button class="btn primary small">Filtrovat</button>
</form>
<p class="hint">Nalezeno: <b><?= count($rows) ?></b></p>
<div class="tbl-wrap"><table class="tbl compact">
<thead><tr><th>VS</th><th>Závodník</th><th>Roč.</th><th>Trať</th><th>Klub / město</th><th>Stav</th><th>Platba</th><th class="r">Cena</th><th>Kupon</th><th>Registrováno</th></tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
  <td><a href="<?= $e($adm('objednavka/' . $r['order_id'])) ?>"><?= $e($r['vs']) ?></a></td>
  <td><a href="<?= $e($adm('objednavka/' . $r['order_id'])) ?>"><b><?= $e($r['last_name']) ?></b> <?= $e($r['first_name']) ?></a><br><small><?= $e($r['email']) ?></small></td>
  <td><?= $e(substr((string) $r['birth_date'], 0, 4)) ?></td>
  <td><?= $e(Util::tr($r['race_name'])) ?><?= $r['bib'] ? ' <small>#' . (int) $r['bib'] . '</small>' : '' ?></td>
  <td><?= $e($r['club']) ?></td>
  <td><span class="badge s-<?= $e($r['status']) ?>"><?= $e(RegAdmin::STATUSES[$r['status']] ?? $r['status']) ?></span></td>
  <td><?= $e(RegAdmin::PAY[$r['payment_status']] ?? $r['payment_status']) ?></td>
  <td class="r"><?= $e($money((int) $r['price_final'])) ?></td>
  <td><?= $e($r['coupon_code']) ?></td>
  <td><small><?= $e(Util::fmtDateTime($r['created_at'])) ?><?= $r['channel'] !== 'online' ? ' · ' . $e($r['channel']) : '' ?></small></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
