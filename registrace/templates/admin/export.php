<?php use Bkl\Admin\RegAdmin; use Bkl\Util; ?>
<p><a href="<?= $e($adm('akce/' . $ev['id'] . '/registrace')) ?>">← Registrace</a></p>
<h1>Export do Excelu: <?= $e(Util::tr($ev['name'])) ?></h1>
<form method="post" class="card" id="exportform"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <p>Šablona:
    <?php foreach ($presets as $k => $p): ?><button type="button" class="btn small secondary" data-preset="<?= $e(implode(',', $p['cols'])) ?>"><?= $e($p['label']) ?></button> <?php endforeach; ?>
    <?php foreach ($templates as $t): ?><button type="button" class="btn small secondary" data-preset="<?= $e(implode(',', (array) json_decode((string) $t['columns'], true))) ?>"><?= $e($t['name']) ?></button> <?php endforeach; ?>
  </p>
  <div class="cols">
    <?php foreach ($all as $k => $label): ?><label class="check"><input type="checkbox" name="cols[]" value="<?= $e($k) ?>" <?= in_array($k, RegAdmin::PRESETS['kompletni']['cols'], true) ? 'checked' : '' ?>> <?= $e($label) ?></label><?php endforeach; ?>
  </div>
  <div class="grid3">
    <div class="field"><label>Trať<select name="race"><option value="">všechny</option><?php foreach ($ev['races'] as $r): ?><option value="<?= (int) $r['id'] ?>"><?= $e(Util::tr($r['name'])) ?></option><?php endforeach; ?></select></label></div>
    <div class="field"><label>Registrace<select name="status"><option value="aktivni">aktivní (bez zrušených)</option><option value="potvrzena">jen potvrzené (zaplacené)</option><option value="ceka_na_platbu">jen čekající na platbu</option><option value="vse">všechny včetně zrušených</option></select></label></div>
    <div class="field"><label>Řazení<select name="sort"><option value="">trať, příjmení</option><option value="bib">startovní číslo</option></select></label></div>
  </div>
  <div class="field"><label>Uložit výběr sloupců jako šablonu (nepovinné)<input name="save_as" placeholder="např. Pro pokladnu"></label></div>
  <button class="btn primary">Stáhnout Excel (.xlsx)</button>
</form>
