<?php use Bkl\Util; $st = ['pripravovana' => 'připravovaná', 'otevreno' => 'registrace otevřené', 'pozastaveno' => 'pozastaveno', 'uzavreno' => 'uzavřeno', 'ukoncena' => 'ukončená', 'archivovana' => 'archiv']; ?>
<h1>Přehled</h1>
<div class="tiles">
  <a class="tile<?= $unmatched ? ' warn' : '' ?>" href="<?= $e($adm('platby')) ?>"><b><?= $unmatched ?></b><span>nespárovaných plateb</span></a>
  <div class="tile<?= !$lastFio ? ' warn' : '' ?>"><b><?= $lastFio ? $e(Util::fmtDateTime($lastFio)) : '—' ?></b><span>poslední stažení plateb Fio</span></div>
  <div class="tile<?= !$lastBackup ? ' warn' : '' ?>"><b><?= $lastBackup ? $e(Util::fmtDateTime($lastBackup)) : '—' ?></b><span>poslední záloha</span></div>
  <a class="tile<?= $mailErrors ? ' warn' : '' ?>" href="<?= $e($adm('system')) ?>"><b><?= $mailErrors ?></b><span>neodeslaných e-mailů</span></a>
</div>
<?php if (!$lastCron): ?><div class="alert">Plánované úlohy zatím nikdy neběžely – nastavte CRON na Wedosu (viz provozní návod).</div><?php endif; ?>

<?php foreach ($events as $ev): $s = $ev['stats']; $m = $ev['money']; ?>
<section class="card">
  <div class="row-between"><h2><a href="<?= $e($adm('akce/' . $ev['id'] . '/registrace')) ?>"><?= $e(Util::tr($ev['name'])) ?></a></h2><span class="badge"><?= $e($st[$ev['status']] ?? $ev['status']) ?></span></div>
  <p class="meta"><?= $e(Util::fmtDateTime($ev['starts_at'])) ?></p>
  <div class="tiles small">
    <div class="tile"><b><?= (int) $s['aktivni'] ?><?= $ev['capacity'] !== null ? ' / ' . (int) $ev['capacity'] : '' ?></b><span>přihlášeno</span></div>
    <div class="tile"><b><?= (int) $s['zaplaceno'] ?></b><span>zaplaceno</span></div>
    <div class="tile"><b><?= (int) $s['ceka'] ?></b><span>čeká na platbu</span></div>
    <?php if ((int) $s['kontrola']): ?><div class="tile warn"><b><?= (int) $s['kontrola'] ?></b><span>ke kontrole</span></div><?php endif; ?>
    <div class="tile"><b><?= $e($money((int) $m['paid'])) ?></b><span>přijato celkem</span></div>
    <div class="tile"><b><?= $e($money((int) $m['entry'])) ?></b><span>startovné (zaplacené)</span></div>
    <div class="tile"><b><?= $e($money((int) $m['products'])) ?></b><span>produkty</span></div>
    <div class="tile"><b><?= $e($money((int) $m['charity'])) ?></b><span>charita</span></div>
    <div class="tile"><b><?= $e($money((int) $m['open'])) ?></b><span>očekáváme</span></div>
  </div>
  <p><?php foreach ($ev['races_occ'] as $r): ?><span class="pill"><?= $e(Util::tr($r['name'])) ?>: <b><?= (int) $r['n'] ?></b><?= $r['capacity'] !== null ? ' / ' . (int) $r['capacity'] : '' ?></span> <?php endforeach; ?></p>
  <p><a href="<?= $e($adm('akce/' . $ev['id'] . '/registrace')) ?>">Registrace</a> · <a href="<?= $e($adm('akce/' . $ev['id'] . '/export')) ?>">Export</a> · <a href="<?= $e($adm('akce/' . $ev['id'])) ?>">Nastavení</a> · <a href="<?= $e($url($ev['slug'])) ?>" target="_blank">Veřejná stránka</a></p>
</section>
<?php endforeach; ?>
<?php if (!$events): ?><p class="card">Zatím žádné akce. <a href="<?= $e($adm('akce/nova')) ?>">Založit akci</a></p><?php endif; ?>

<?php if ($problems): ?>
<section class="card"><h2>Poslední upozornění</h2><ul class="log">
<?php foreach ($problems as $p): ?><li class="lv-<?= $e($p['level']) ?>"><small><?= $e(Util::fmtDateTime($p['created_at'])) ?></small> <?= $e($p['message']) ?></li><?php endforeach; ?>
</ul><p><a href="<?= $e($adm('system')) ?>">Všechny záznamy</a></p></section>
<?php endif; ?>
