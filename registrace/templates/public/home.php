<?php use Bkl\Util; ?>
<h1>Registrace na závody</h1>
<?php if (!$events): ?><p class="card">Momentálně nejsou otevřené žádné registrace.</p><?php endif; ?>
<div class="list">
<?php foreach ($events as $ev): ?>
  <a class="card event-link" href="<?= $e($url($ev['slug'])) ?>">
    <strong><?= $e($tr($ev['name'])) ?></strong>
    <span><?= $e(Util::fmtDateTime($ev['starts_at'])) ?><?= $ev['venue'] ? ' · ' . $e($ev['venue']) : '' ?></span>
  </a>
<?php endforeach; ?>
</div>
