<!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= $e($title) ?> | BKL Registrace – administrace</title>
<link rel="stylesheet" href="<?= $e($asset('app.css')) ?>">
<link rel="stylesheet" href="<?= $e($asset('admin.css')) ?>">
</head>
<body class="admin">
<?php if ($user): ?>
<header class="adm-top">
  <a class="adm-brand" href="<?= $e($adm('')) ?>">BKL Registrace</a>
  <nav>
    <a href="<?= $e($adm('')) ?>">Přehled</a>
    <a href="<?= $e($adm('akce')) ?>">Akce</a>
    <a href="<?= $e($adm('platby')) ?>">Platby</a>
    <a href="<?= $e($adm('system')) ?>">Systém</a>
    <?php if ($user['role'] === 'hlavni_admin'): ?><a href="<?= $e($adm('uzivatele')) ?>">Uživatelé</a><?php endif; ?>
  </nav>
  <span class="adm-user"><?= $e($user['name']) ?> · <a href="<?= $e($adm('heslo')) ?>">heslo</a> ·
    <form method="post" action="<?= $e($adm('logout')) ?>" class="inline"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><button class="link">odhlásit</button></form></span>
</header>
<?php endif; ?>
<main class="adm-wrap">
<?php foreach ($flash as [$t, $m]): ?><div class="<?= $t === 'err' ? 'alert' : 'ok-box' ?>" role="status"><?= $e($m) ?></div><?php endforeach; ?>
<?= $content ?>
</main>
<script src="<?= $e($asset('admin.js')) ?>" defer></script>
</body>
</html>
