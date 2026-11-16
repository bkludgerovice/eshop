<?php /** @var callable $e @var string $content @var bool $embed */ ?>
<!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($title ?? 'Registrace') ?> | BK Ludgeřovice</title>
<?php if (!empty($noindex)): ?><meta name="robots" content="noindex"><?php endif; ?>
<?php if (!empty($ev) && empty($noindex)): ?>
<meta property="og:title" content="<?= $e($tr($ev['name'])) ?> – registrace">
<meta property="og:description" content="<?= $e(trim(($ev['venue'] ?? '') . ' ' . \Bkl\Util::fmtDateTime($ev['starts_at']))) ?>">
<?php if (!empty($ev['image_url'])): ?><meta property="og:image" content="<?= $e($ev['image_url']) ?>"><?php endif; ?>
<?php endif; ?>
<link rel="stylesheet" href="<?= $e($asset('app.css')) ?>">
<?php if (!empty($ev['color_primary']) && preg_match('/^#[0-9a-fA-F]{6}$/', $ev['color_primary'])): ?>
<style>:root{--accent:<?= $ev['color_primary'] ?>}</style>
<?php endif; ?>
</head>
<body class="<?= $embed ? 'embed' : 'standalone' ?>">
<?php if (!$embed): ?>
<header class="top">
  <a class="brand" href="https://www.bkludgerovice.cz"><img src="<?= $e($url('../logo.png')) ?>" alt="" width="40" height="40"><span>BK Ludgeřovice<small>registrace na závody</small></span></a>
</header>
<?php endif; ?>
<main class="wrap">
<?= $content ?>
</main>
<?php if (!$embed): ?>
<footer class="foot">Běžecký klub Ludgeřovice, z. s. · <a href="https://www.bkludgerovice.cz">bkludgerovice.cz</a></footer>
<?php endif; ?>
<script src="<?= $e($asset('frame.js')) ?>" defer></script>
</body>
</html>
