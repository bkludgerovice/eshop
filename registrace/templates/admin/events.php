<?php use Bkl\Util; ?>
<div class="row-between"><h1>Akce</h1><?php if ($canEdit): ?><a class="btn primary" href="<?= $e($adm('akce/nova')) ?>">+ Nová akce</a><?php endif; ?></div>
<div class="tbl-wrap"><table class="tbl">
<thead><tr><th>Akce</th><th>Datum</th><th>Stav</th><th>Přihlášeno</th><th></th></tr></thead>
<tbody>
<?php foreach ($events as $ev): ?>
<tr><td><b><?= $e(Util::tr($ev['name'])) ?></b><br><small>/<?= $e($ev['slug']) ?></small></td><td><?= $e(Util::fmtDateTime($ev['starts_at'])) ?></td><td><?= $e($ev['status']) ?></td>
<td><?= (int) $ev['n'] ?><?= $ev['capacity'] !== null ? ' / ' . (int) $ev['capacity'] : '' ?></td>
<td><a href="<?= $e($adm('akce/' . $ev['id'] . '/registrace')) ?>">Registrace</a> · <a href="<?= $e($adm('akce/' . $ev['id'])) ?>">Nastavení</a></td></tr>
<?php endforeach; ?>
</tbody></table></div>
