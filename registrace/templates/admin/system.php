<?php use Bkl\Util; $ro = $canEdit ? '' : ' disabled'; ?>
<h1>Systém</h1>
<section class="card"><h2>Ruční spuštění</h2>
<form method="post" class="inline-row"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><fieldset<?= $ro ?>>
  <button name="action" value="fio" class="btn small secondary">Stáhnout platby z Fio</button>
  <button name="action" value="mail" class="btn small secondary">Znovu odeslat neodeslané e-maily</button>
  <button name="action" value="cron" class="btn small secondary">Spustit plánované úlohy</button>
  <?php if ($user['role'] === 'hlavni_admin'): ?><button name="action" value="backup" class="btn small secondary">Zálohovat teď</button><?php endif; ?>
</fieldset></form>
<p class="hint">PHP <?= $e($php) ?> · zálohy na serveru: <?= count($backups) ?><?= $backups ? ' (poslední ' . $e($backups[0]) . ')' : '' ?></p></section>
<section class="card"><h2>Chyby a upozornění</h2><ul class="log">
<?php foreach ($logs as $l): ?><li class="lv-<?= $e($l['level']) ?>"><small><?= $e(Util::fmtDateTime($l['created_at'])) ?> · <?= $e($l['source']) ?></small> <?= $e($l['message']) ?></li><?php endforeach; ?>
</ul></section>
<section class="card"><h2>Plánované úlohy</h2><ul class="log">
<?php foreach ($jobs as $j): ?><li class="<?= $j['result'] === 'chyba' ? 'lv-chyba' : '' ?>"><small><?= $e(Util::fmtDateTime($j['started_at'])) ?></small> <b><?= $e($j['job']) ?></b> <?= $e($j['result']) ?> – <?= $e($j['message']) ?></li><?php endforeach; ?>
</ul></section>
<section class="card"><h2>E-maily</h2><ul class="log">
<?php foreach ($mails as $m): ?><li class="<?= $m['status'] === 'chyba' ? 'lv-chyba' : '' ?>"><small><?= $e(Util::fmtDateTime($m['created_at'])) ?></small> <?= $e($m['to_email']) ?> – <?= $e($m['subject']) ?> – <b><?= $e($m['status']) ?></b> (pokusů <?= (int) $m['attempts'] ?>)<?= $m['last_error'] ? ' – ' . $e($m['last_error']) : '' ?></li><?php endforeach; ?>
</ul></section>
<section class="card"><h2>Auditní záznam</h2><ul class="log">
<?php foreach ($audit as $a): ?><li><small><?= $e(Util::fmtDateTime($a['created_at'])) ?></small> <?= $e($a['entity'] . ' #' . $a['entity_id']) ?> <b><?= $e($a['action']) ?></b> – <?= $e($a['actor']) ?></li><?php endforeach; ?>
</ul></section>
