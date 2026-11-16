<?php
use Bkl\Util;

$dtl = static fn ($v): string => $v ? str_replace(' ', 'T', substr((string) $v, 0, 16)) : '';
$kc = static fn ($m): string => $m === null ? '' : rtrim(rtrim(number_format(((int) $m) / 100, 2, '.', ''), '0'), '.');
$hid = static fn (): string => '<input type="hidden" name="csrf" value="' . Util::e($csrf) . '">';
$raceName = [];
foreach ($allRaces as $r) {
    $raceName[(int) $r['id']] = Util::tr($r['name']);
}
$raceSelect = static function (?int $sel, string $empty = 'všechny tratě') use ($allRaces, $e): string {
    $h = '<select name="race_id"><option value="">' . $e($empty) . '</option>';
    foreach ($allRaces as $r) {
        $h .= '<option value="' . (int) $r['id'] . '"' . ($sel === (int) $r['id'] ? ' selected' : '') . '>' . $e(Util::tr($r['name'])) . '</option>';
    }
    return $h . '</select>';
};
$publicUrl = $url($ev['slug']);
$embedCode = '<div data-bkl-registrace="' . $ev['slug'] . '"></div>' . "\n" . '<script src="' . $url('embed.js') . '" async></script>';
$embedList = '<div data-bkl-registrace="' . $ev['slug'] . '" data-view="startovka"></div>' . "\n" . '<script src="' . $url('embed.js') . '" async></script>';
$ro = $canEdit ? '' : ' disabled';
?>
<div class="row-between">
  <h1><?= $e(Util::tr($ev['name'])) ?></h1>
  <span><a href="<?= $e($adm('akce/' . $ev['id'] . '/registrace')) ?>">Registrace (<?= (int) $regCount ?>)</a> · <a href="<?= $e($publicUrl) ?>" target="_blank">Veřejná stránka ↗</a></span>
</div>
<nav class="subnav"><a href="#nastaveni">Nastavení</a><a href="#trat">Tratě</a><a href="#cena">Ceny</a><a href="#vek">Věkové slevy</a><a href="#kupon">Kupony</a><a href="#pole">Pole formuláře</a><a href="#charita">Charita</a><a href="#kategorie">Kategorie</a><a href="#vlozeni">Vložení na web</a></nav>

<section class="card" id="nastaveni">
<h2>Základní nastavení</h2>
<form method="post" action="<?= $e($adm('akce/' . $ev['id'])) ?>"><?= $hid() ?>
<fieldset<?= $ro ?>>
<div class="grid3">
  <div class="field"><label>Stav akce
    <select name="status">
      <?php foreach (['pripravovana' => 'Připravovaná (registrace zavřené)', 'otevreno' => 'Registrace otevřené', 'pozastaveno' => 'Pozastaveno', 'uzavreno' => 'Uzavřeno', 'ukoncena' => 'Ukončená', 'archivovana' => 'Archivovaná'] as $k => $v): ?>
        <option value="<?= $k ?>" <?= $ev['status'] === $k ? 'selected' : '' ?>><?= $v ?></option>
      <?php endforeach; ?>
    </select></label><small class="hint">„Vyprodáno“ se zobrazí automaticky po naplnění kapacity.</small></div>
  <div class="field"><label>Název<input name="name" value="<?= $e(Util::tr($ev['name'])) ?>" required></label></div>
  <div class="field"><label>Adresa (slug)<input name="slug" value="<?= $e($ev['slug']) ?>" required pattern="[a-z0-9\-]+"></label><small class="hint"><?= $e($publicUrl) ?></small></div>
  <div class="field"><label>Start<input type="datetime-local" name="starts_at" value="<?= $e($dtl($ev['starts_at'])) ?>" required></label></div>
  <div class="field"><label>Místo<input name="venue" value="<?= $e($ev['venue']) ?>"></label></div>
  <div class="field"><label>Společná kapacita akce<input name="capacity" value="<?= $e($ev['capacity']) ?>" inputmode="numeric" placeholder="bez omezení"></label></div>
  <div class="field"><label>Registrace od<input type="datetime-local" name="reg_opens_at" value="<?= $e($dtl($ev['reg_opens_at'])) ?>"></label></div>
  <div class="field"><label>Online registrace do<input type="datetime-local" name="reg_closes_at" value="<?= $e($dtl($ev['reg_closes_at'])) ?>"></label></div>
  <div class="field"><label>Číslo akce ve VS (1–99)<input name="vs_code" value="<?= (int) $ev['vs_code'] ?>" inputmode="numeric"></label><small class="hint">VS: 8 <?= substr((string) $ev['starts_at'], 2, 2) ?> <?= sprintf('%02d', $ev['vs_code']) ?> xxxx. Po první objednávce nelze měnit.</small></div>
</div>
<div class="field"><label>Popis akce<textarea name="description" rows="4"><?= $e(Util::tr($ev['description'])) ?></textarea></label></div>
<div class="grid3">
  <div class="field"><label>Bankovní účet
    <select name="bank_account_id"><?php foreach ($banks as $b): ?><option value="<?= (int) $b['id'] ?>" <?= (int) $b['id'] === (int) $ev['bank_account_id'] ? 'selected' : '' ?>><?= $e($b['name'] . ' – ' . $b['account_number'] . '/' . $b['bank_code']) ?></option><?php endforeach; ?></select></label></div>
  <div class="field"><label>Splatnost (dny)<input name="payment_due_days" value="<?= (int) $ev['payment_due_days'] ?>" inputmode="numeric"></label></div>
  <div class="field"><label>Upomínka (dní před splatností, 0 = ne)<input name="reminder_days_before_due" value="<?= (int) $ev['reminder_days_before_due'] ?>" inputmode="numeric"></label></div>
  <div class="field"><label>Po splatnosti
    <select name="overdue_action"><option value="rucne" <?= $ev['overdue_action'] === 'rucne' ? 'selected' : '' ?>>označit k ručnímu rozhodnutí</option><option value="zrusit" <?= $ev['overdue_action'] === 'zrusit' ? 'selected' : '' ?>>automaticky zrušit</option></select></label></div>
  <div class="field"><label>Tolerance po splatnosti (dny)<input name="overdue_grace_days" value="<?= (int) $ev['overdue_grace_days'] ?>" inputmode="numeric"></label></div>
  <div class="field"><label>Samoobslužný převod registrace do<input type="datetime-local" name="transfer_deadline" value="<?= $e($dtl($ev['transfer_deadline'])) ?>"></label><small class="hint">(převody – verze 1b)</small></div>
  <div class="field"><label>Max. závodníků v jedné přihlášce<input name="max_runners_per_order" value="<?= (int) $ev['max_runners_per_order'] ?>" inputmode="numeric"></label></div>
  <div class="field"><label>Souhlas rodiče u mladších než (let)<input name="guardian_age" value="<?= (int) $ev['guardian_age'] ?>" inputmode="numeric"></label></div>
  <div class="field"><label>Barva<input type="color" name="color_primary" value="<?= $e($ev['color_primary']) ?>"></label></div>
  <div class="field"><label>Kontaktní e-mail<input type="email" name="contact_email" value="<?= $e($ev['contact_email']) ?>"></label></div>
  <div class="field"><label>Kontaktní telefon<input name="contact_phone" value="<?= $e($ev['contact_phone']) ?>"></label></div>
  <div class="field"><label>Web akce<input type="url" name="web_url" value="<?= $e($ev['web_url']) ?>"></label></div>
  <div class="field"><label>Odkaz na podmínky závodu<input type="url" name="terms_url" value="<?= $e($ev['terms_url']) ?>"></label></div>
  <div class="field"><label>Odkaz na zpracování os. údajů<input type="url" name="gdpr_url" value="<?= $e($ev['gdpr_url']) ?>"></label></div>
  <div class="field"><label>Logo (URL obrázku)<input type="url" name="logo_url" value="<?= $e($ev['logo_url']) ?>"></label></div>
  <div class="field"><label>Obrázek pro Facebook (URL)<input type="url" name="image_url" value="<?= $e($ev['image_url']) ?>"></label></div>
  <div class="field"><label>Jméno odesílatele e-mailů<input name="sender_name" value="<?= $e($ev['sender_name']) ?>" placeholder="Běžecký klub Ludgeřovice – Registrace"></label></div>
  <div class="field"><label>Odpovědi na e-maily (reply-to)<input type="email" name="reply_to" value="<?= $e($ev['reply_to']) ?>"></label></div>
  <div class="field"><label>PIN pro prezenci (nový)<input name="checkin_pin" value="" autocomplete="off" placeholder="<?= $ev['checkin_pin_hash'] ? 'nastaven – vyplňte jen pro změnu' : 'nenastaven' ?>"></label><small class="hint">(prezence – verze 1b)</small></div>
</div>
<div class="field"><label>Weby, které smí formulář vložit (iframe), oddělené mezerou<input name="embed_origins" value="<?= $e($ev['embed_origins']) ?>"></label></div>
<label class="check"><input type="checkbox" name="onsite_enabled" value="1" <?= $ev['onsite_enabled'] ? 'checked' : '' ?>> Registrace na místě v den závodu (pokud zbydou místa)</label>
<label class="check"><input type="checkbox" name="public_list_enabled" value="1" <?= $ev['public_list_enabled'] ? 'checked' : '' ?>> Veřejná startovní listina</label>
<label class="check"><input type="checkbox" name="discount_applies_to_products" value="1" <?= $ev['discount_applies_to_products'] ? 'checked' : '' ?>> Slevy platí i na produkty (jinak jen na startovné)</label>
<p><button class="btn primary">Uložit nastavení</button></p>
</fieldset>
</form>
</section>

<section class="card" id="trat">
<h2>Tratě</h2>
<div class="tbl-wrap"><table class="tbl compact">
<thead><tr><th>Název</th><th>km</th><th>Kapacita</th><th>Věk od–do</th><th>Čísla od–do</th><th>Pořadí</th><th></th></tr></thead>
<tbody>
<?php foreach ($allRaces as $r): $f = 'tr' . $r['id']; ?>
<tr class="<?= $r['active'] ? '' : 'muted' ?>">
  <td><input form="<?= $f ?>" name="name" value="<?= $e(Util::tr($r['name'])) ?>"></td>
  <td><input form="<?= $f ?>" name="distance_km" value="<?= $e($r['distance_km'] === null ? '' : rtrim(rtrim((string) $r['distance_km'], '0'), '.')) ?>" class="w5"></td>
  <td><input form="<?= $f ?>" name="capacity" value="<?= $e($r['capacity']) ?>" class="w5" placeholder="—"></td>
  <td><input form="<?= $f ?>" name="min_age" value="<?= $e($r['min_age']) ?>" class="w4"> – <input form="<?= $f ?>" name="max_age" value="<?= $e($r['max_age']) ?>" class="w4"></td>
  <td><input form="<?= $f ?>" name="bib_from" value="<?= $e($r['bib_from']) ?>" class="w5"> – <input form="<?= $f ?>" name="bib_to" value="<?= $e($r['bib_to']) ?>" class="w5"></td>
  <td><input form="<?= $f ?>" name="sort_order" value="<?= (int) $r['sort_order'] ?>" class="w4"></td>
  <td class="nowrap"><form id="<?= $f ?>" method="post" action="<?= $e($adm('akce/' . $ev['id'] . '/trat')) ?>"><?= $hid() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
    <fieldset<?= $ro ?>><button name="action" value="save" class="btn small">Uložit</button>
    <button name="action" value="<?= $r['active'] ? 'deactivate' : 'activate' ?>" class="btn small secondary"><?= $r['active'] ? 'Skrýt' : 'Obnovit' ?></button></fieldset></form></td>
</tr>
<?php endforeach; ?>
<tr class="new">
  <td><input form="trnew" name="name" placeholder="nová trať, např. 10 km"></td>
  <td><input form="trnew" name="distance_km" class="w5"></td>
  <td><input form="trnew" name="capacity" class="w5" placeholder="—"></td>
  <td><input form="trnew" name="min_age" class="w4"> – <input form="trnew" name="max_age" class="w4"></td>
  <td><input form="trnew" name="bib_from" class="w5"> – <input form="trnew" name="bib_to" class="w5"></td>
  <td><input form="trnew" name="sort_order" class="w4" value="<?= count($allRaces) + 1 ?>"></td>
  <td><form id="trnew" method="post" action="<?= $e($adm('akce/' . $ev['id'] . '/trat')) ?>"><?= $hid() ?><fieldset<?= $ro ?>><button name="action" value="save" class="btn small primary">Přidat</button></fieldset></form></td>
</tr>
</tbody></table></div>
<p class="hint">Kapacita tratě je nepovinná – platí vždy i společná kapacita akce. Věk se počítá podle ročníku narození.</p>
</section>

<section class="card" id="cena">
<h2>Cenové hladiny</h2>
<p class="hint">Platí hladina podle data registrace. Hladina pro konkrétní trať má přednost před hladinou „všechny tratě“. Registrace na místě použije hladinu „na místě“, jinak online. Cena u existující objednávky se nemění.</p>
<div class="tbl-wrap"><table class="tbl compact">
<thead><tr><th>Název</th><th>Trať</th><th>Platí od</th><th>Platí do</th><th>Kanál</th><th>Cena Kč</th><th></th></tr></thead>
<tbody>
<?php foreach ($ev['tiers'] as $t): $f = 'ct' . $t['id']; ?>
<tr>
  <td><input form="<?= $f ?>" name="name" value="<?= $e($t['name']) ?>"></td>
  <td><?= str_replace('<select name="race_id">', '<select name="race_id" form="' . $f . '">', $raceSelect($t['race_id'] === null ? null : (int) $t['race_id'])) ?></td>
  <td><input form="<?= $f ?>" type="datetime-local" name="valid_from" value="<?= $e($dtl($t['valid_from'])) ?>"></td>
  <td><input form="<?= $f ?>" type="datetime-local" name="valid_to" value="<?= $e($dtl($t['valid_to'])) ?>"></td>
  <td><select form="<?= $f ?>" name="channel"><option value="online">online</option><option value="na_miste" <?= $t['channel'] === 'na_miste' ? 'selected' : '' ?>>na místě</option></select></td>
  <td><input form="<?= $f ?>" name="amount" value="<?= $e($kc($t['amount'])) ?>" class="w5"></td>
  <td class="nowrap"><form id="<?= $f ?>" method="post" action="<?= $e($adm('akce/' . $ev['id'] . '/cena')) ?>"><?= $hid() ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><fieldset<?= $ro ?>>
    <button name="action" value="save" class="btn small">Uložit</button> <button name="action" value="delete" class="btn small secondary" data-confirm="Smazat cenovou hladinu?">Smazat</button></fieldset></form></td>
</tr>
<?php endforeach; ?>
<tr class="new">
  <td><input form="ctnew" name="name" placeholder="např. do 31. 12."></td>
  <td><?= str_replace('<select name="race_id">', '<select name="race_id" form="ctnew">', $raceSelect(null)) ?></td>
  <td><input form="ctnew" type="datetime-local" name="valid_from"></td>
  <td><input form="ctnew" type="datetime-local" name="valid_to"></td>
  <td><select form="ctnew" name="channel"><option value="online">online</option><option value="na_miste">na místě</option></select></td>
  <td><input form="ctnew" name="amount" class="w5" placeholder="170"></td>
  <td><form id="ctnew" method="post" action="<?= $e($adm('akce/' . $ev['id'] . '/cena')) ?>"><?= $hid() ?><fieldset<?= $ro ?>><button name="action" value="save" class="btn small primary">Přidat</button></fieldset></form></td>
</tr>
</tbody></table></div>
</section>

<section class="card" id="vek">
<h2>Věkové slevy</h2>
<p class="hint">Věk = rok konání akce − rok narození. Slevy se nesčítají s kupony – uplatní se vyšší.</p>
<?php foreach ($ev['age_rules'] as $a): ?>
<form method="post" action="<?= $e($adm('akce/' . $ev['id'] . '/vek')) ?>" class="inline-row"><?= $hid() ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><fieldset<?= $ro ?>>
  <input name="name" value="<?= $e($a['name']) ?>"> věk do <input name="age_max" value="<?= $e($a['age_max']) ?>" class="w4"> nebo od <input name="age_min" value="<?= $e($a['age_min']) ?>" class="w4"> sleva <input name="percent" value="<?= (int) $a['percent'] ?>" class="w4"> %
  <button name="action" value="save" class="btn small">Uložit</button> <button name="action" value="delete" class="btn small secondary" data-confirm="Smazat slevu?">Smazat</button></fieldset></form>
<?php endforeach; ?>
<form method="post" action="<?= $e($adm('akce/' . $ev['id'] . '/vek')) ?>" class="inline-row new"><?= $hid() ?><fieldset<?= $ro ?>>
  <input name="name" placeholder="Děti do 15 let"> věk do <input name="age_max" class="w4"> nebo od <input name="age_min" class="w4"> sleva <input name="percent" class="w4" value="50"> %
  <button name="action" value="save" class="btn small primary">Přidat</button></fieldset></form>
</section>

<section class="card" id="kupon">
<h2>Slevové kupony</h2>
<p class="hint">Členský kupon: jeden společný kód, neomezený počet použití – kód můžete kdykoli změnit. Partnerské kupony: jednorázové unikátní kódy (každý jen jednou).</p>
<?php $shared = array_filter($coupons, static fn ($c) => $c['kind'] !== 'jednorazovy'); $single = array_filter($coupons, static fn ($c) => $c['kind'] === 'jednorazovy'); ?>
<?php foreach ($shared as $c): ?>
<form method="post" action="<?= $e($adm('akce/' . $ev['id'] . '/kupon')) ?>" class="inline-row<?= $c['active'] ? '' : ' muted' ?>"><?= $hid() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="kind" value="<?= $e($c['kind']) ?>"><fieldset<?= $ro ?>>
  <input name="code" value="<?= $e($c['code']) ?>" class="w10"> <input name="label" value="<?= $e($c['label']) ?>" placeholder="popis">
  sleva <input name="percent" value="<?= $e($c['percent']) ?>" class="w4"> % nebo <input name="fixed" value="<?= $e($kc($c['fixed_amount'])) ?>" class="w5"> Kč,
  max. použití <input name="max_uses" value="<?= $e($c['max_uses']) ?>" class="w5" placeholder="∞"> · použito <b><?= (int) $c['used_count'] ?>×</b>
  <button name="action" value="save" class="btn small">Uložit</button> <button name="action" value="toggle" class="btn small secondary"><?= $c['active'] ? 'Vypnout' : 'Zapnout' ?></button></fieldset></form>
<?php endforeach; ?>
<form method="post" action="<?= $e($adm('akce/' . $ev['id'] . '/kupon')) ?>" class="inline-row new"><?= $hid() ?><fieldset<?= $ro ?>>
  <select name="kind"><option value="clensky_spolecny">členský (společný)</option><option value="obecny">obecný</option></select>
  <input name="code" placeholder="KÓD" class="w10"> <input name="label" placeholder="popis"> sleva <input name="percent" class="w4" value="50"> % nebo <input name="fixed" class="w5"> Kč, max. použití <input name="max_uses" class="w5" placeholder="∞">
  <button name="action" value="save" class="btn small primary">Přidat kupon</button></fieldset></form>

<h3>Jednorázové partnerské kódy</h3>
<form method="post" action="<?= $e($adm('akce/' . $ev['id'] . '/kupony')) ?>" class="inline-row new"><?= $hid() ?><fieldset<?= $ro ?>>
  Vygenerovat <input name="count" class="w4" value="20"> kódů s předponou <input name="prefix" class="w5" value="PARTNER"> se slevou <input name="percent" class="w4" value="100"> %, označení <input name="label" placeholder="např. Partner XY">
  <button class="btn small primary">Vygenerovat</button></fieldset></form>
<?php if ($single): ?>
<details><summary><?= count($single) ?> jednorázových kódů (nepoužito <?= count(array_filter($single, static fn ($c) => (int) $c['used_count'] === 0 && $c['active'])) ?>)</summary>
<div class="tbl-wrap"><table class="tbl compact"><thead><tr><th>Kód</th><th>Označení</th><th>Sleva</th><th>Použito</th><th></th></tr></thead><tbody>
<?php foreach ($single as $c): ?><tr class="<?= $c['active'] ? '' : 'muted' ?>"><td><code><?= $e($c['code']) ?></code></td><td><?= $e($c['label']) ?></td><td><?= (int) $c['percent'] ?> %</td><td><?= (int) $c['used_count'] ? 'ano' : '—' ?></td>
<td><form method="post" action="<?= $e($adm('akce/' . $ev['id'] . '/kupon')) ?>"><?= $hid() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><fieldset<?= $ro ?>><button name="action" value="toggle" class="btn small secondary"><?= $c['active'] ? 'Zneplatnit' : 'Obnovit' ?></button></fieldset></form></td></tr><?php endforeach; ?>
</tbody></table></div></details>
<?php endif; ?>
<?php if ($couponUsers): ?>
<details><summary>Kdo použil kupon (<?= count($couponUsers) ?>)</summary><ul>
<?php foreach ($couponUsers as $u): ?><li><code><?= $e($u['code']) ?></code> – <a href="<?= $e($adm('objednavka/' . $u['order_id'])) ?>"><?= $e($u['first_name'] . ' ' . $u['last_name']) ?></a> (VS <?= $e($u['vs']) ?>)</li><?php endforeach; ?>
</ul></details>
<?php endif; ?>
</section>

<section class="card" id="pole">
<h2>Vlastní pole formuláře a produkty</h2>
<p class="hint">Např. výběr teplého jídla (výběr z možností s příplatkem). Pole s příplatkem se počítají jako produkty. Pole lze omezit na jednu trať.</p>
<?php foreach ($allFields as $f): $opts = array_filter($allOptions, static fn ($o) => (int) $o['field_id'] === (int) $f['id']); ?>
<div class="subcard<?= $f['active'] ? '' : ' muted' ?>">
<form method="post" action="<?= $e($adm('akce/' . $ev['id'] . '/pole')) ?>" class="inline-row"><?= $hid() ?><input type="hidden" name="id" value="<?= (int) $f['id'] ?>"><fieldset<?= $ro ?>>
  <input name="label" value="<?= $e(Util::tr($f['label'])) ?>" class="w20">
  <select name="type"><?php foreach (['select' => 'výběr z možností', 'multiselect' => 'vícenásobný výběr', 'checkbox' => 'zaškrtávátko', 'text' => 'text', 'number' => 'číslo', 'date' => 'datum'] as $k => $v): ?><option value="<?= $k ?>" <?= $f['type'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
  <?= $raceSelect($f['race_id'] === null ? null : (int) $f['race_id']) ?>
  <label class="check inline"><input type="checkbox" name="required" value="1" <?= $f['required'] ? 'checked' : '' ?>> povinné</label>
  <label class="check inline"><input type="checkbox" name="is_product" value="1" <?= $f['is_product'] ? 'checked' : '' ?>> produkt</label>
  pořadí <input name="sort_order" value="<?= (int) $f['sort_order'] ?>" class="w4">
  <input name="help" value="<?= $e(Util::tr($f['help'])) ?>" placeholder="nápověda (nepovinné)">
  <button name="action" value="save" class="btn small">Uložit</button> <button name="action" value="toggle" class="btn small secondary"><?= $f['active'] ? 'Skrýt' : 'Zobrazit' ?></button></fieldset></form>
<?php if (in_array($f['type'], ['select', 'multiselect', 'checkbox'], true)): ?>
  <div class="opts">
  <?php foreach ($opts as $o): ?>
  <form method="post" action="<?= $e($adm('akce/' . $ev['id'] . '/moznost')) ?>" class="inline-row<?= $o['active'] ? '' : ' muted' ?>"><?= $hid() ?><input type="hidden" name="id" value="<?= (int) $o['id'] ?>"><input type="hidden" name="field_id" value="<?= (int) $f['id'] ?>"><fieldset<?= $ro ?>>
    ↳ <input name="label" value="<?= $e(Util::tr($o['label'])) ?>"> příplatek <input name="surcharge" value="<?= $e($kc($o['surcharge'])) ?>" class="w5"> Kč, pořadí <input name="sort_order" value="<?= (int) $o['sort_order'] ?>" class="w4">
    <button name="action" value="save" class="btn small">Uložit</button> <button name="action" value="toggle" class="btn small secondary"><?= $o['active'] ? 'Skrýt' : 'Zobrazit' ?></button></fieldset></form>
  <?php endforeach; ?>
  <form method="post" action="<?= $e($adm('akce/' . $ev['id'] . '/moznost')) ?>" class="inline-row new"><?= $hid() ?><input type="hidden" name="field_id" value="<?= (int) $f['id'] ?>"><fieldset<?= $ro ?>>
    ↳ <input name="label" placeholder="nová možnost"> příplatek <input name="surcharge" class="w5" placeholder="0"> Kč <button name="action" value="save" class="btn small primary">Přidat možnost</button></fieldset></form>
  </div>
<?php endif; ?>
</div>
<?php endforeach; ?>
<form method="post" action="<?= $e($adm('akce/' . $ev['id'] . '/pole')) ?>" class="inline-row new"><?= $hid() ?><fieldset<?= $ro ?>>
  <input name="label" placeholder="název nového pole, např. Teplé jídlo" class="w20">
  <select name="type"><option value="select">výběr z možností</option><option value="multiselect">vícenásobný výběr</option><option value="checkbox">zaškrtávátko</option><option value="text">text</option><option value="number">číslo</option><option value="date">datum</option></select>
  <?= $raceSelect(null) ?>
  <label class="check inline"><input type="checkbox" name="required" value="1"> povinné</label>
  <label class="check inline"><input type="checkbox" name="is_product" value="1"> produkt</label>
  <button name="action" value="save" class="btn small primary">Přidat pole</button></fieldset></form>
</section>

<section class="card" id="charita">
<h2>Charitativní příspěvek</h2>
<form method="post" action="<?= $e($adm('akce/' . $ev['id'] . '/charita')) ?>"><?= $hid() ?><fieldset<?= $ro ?>>
  <label class="check"><input type="checkbox" name="active" value="1" <?= $charityAll && $charityAll['active'] ? 'checked' : '' ?>> Nabízet dobrovolný příspěvek</label>
  <div class="grid3">
    <div class="field"><label>Účel (zobrazí se ve formuláři)<input name="purpose" value="<?= $e($charityAll ? Util::tr($charityAll['purpose']) : '') ?>"></label></div>
    <div class="field"><label>Příjemce<input name="recipient" value="<?= $e($charityAll['recipient'] ?? '') ?>"></label></div>
    <div class="field"><label>Přednastavené částky (Kč, čárkou)<input name="preset_amounts" value="<?= $e($charityAll['preset_amounts'] ?? '50,100,200') ?>"></label></div>
  </div>
  <label class="check"><input type="checkbox" name="allow_custom" value="1" <?= !$charityAll || $charityAll['allow_custom'] ? 'checked' : '' ?>> Povolit vlastní částku</label>
  <p class="hint">Příspěvek je součástí jedné platby na klubový účet; systém ho eviduje odděleně. Vybraná částka je v přehledu.</p>
  <button class="btn primary">Uložit</button></fieldset></form>
</section>

<section class="card" id="kategorie">
<h2>Kategorie (pro časomíru)</h2>
<p class="hint">Přiřazují se automaticky podle tratě, pohlaví a věku (podle ročníku). Použije se první vyhovující v pořadí.</p>
<?php foreach ($categories as $c): ?>
<form method="post" action="<?= $e($adm('akce/' . $ev['id'] . '/kategorie')) ?>" class="inline-row"><?= $hid() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><fieldset<?= $ro ?>>
  <input name="code" value="<?= $e($c['code']) ?>" class="w5"> <input name="name" value="<?= $e($c['name']) ?>"> <?= $raceSelect($c['race_id'] === null ? null : (int) $c['race_id']) ?>
  <select name="gender"><option value="">obě pohlaví</option><option value="M" <?= $c['gender'] === 'M' ? 'selected' : '' ?>>muži</option><option value="Z" <?= $c['gender'] === 'Z' ? 'selected' : '' ?>>ženy</option></select>
  věk <input name="age_from" value="<?= $e($c['age_from']) ?>" class="w4">–<input name="age_to" value="<?= $e($c['age_to']) ?>" class="w4"> pořadí <input name="sort_order" value="<?= (int) $c['sort_order'] ?>" class="w4">
  <button name="action" value="save" class="btn small">Uložit</button> <button name="action" value="delete" class="btn small secondary" data-confirm="Smazat kategorii?">Smazat</button></fieldset></form>
<?php endforeach; ?>
<form method="post" action="<?= $e($adm('akce/' . $ev['id'] . '/kategorie')) ?>" class="inline-row new"><?= $hid() ?><fieldset<?= $ro ?>>
  <input name="code" placeholder="M40" class="w5"> <input name="name" placeholder="Muži 40–49"> <?= $raceSelect(null) ?>
  <select name="gender"><option value="">obě pohlaví</option><option value="M">muži</option><option value="Z">ženy</option></select>
  věk <input name="age_from" class="w4">–<input name="age_to" class="w4"> pořadí <input name="sort_order" class="w4" value="<?= count($categories) + 1 ?>">
  <button name="action" value="save" class="btn small primary">Přidat</button></fieldset></form>
</section>

<section class="card" id="vlozeni">
<h2>Vložení na web (WordPress / Elementor)</h2>
<p>Samostatná adresa pro sdílení (Facebook, QR kód): <a href="<?= $e($publicUrl) ?>" target="_blank"><?= $e($publicUrl) ?></a></p>
<p>Kód pro vložení formuláře do HTML widgetu Elementoru:</p>
<textarea readonly rows="3" class="code" id="embed-form"><?= $e($embedCode) ?></textarea>
<button type="button" class="btn secondary small" data-copy="embed-form">Zkopírovat kód pro vložení</button>
<p>Kód pro vložení startovní listiny:</p>
<textarea readonly rows="3" class="code" id="embed-list"><?= $e($embedList) ?></textarea>
<button type="button" class="btn secondary small" data-copy="embed-list">Zkopírovat kód</button>
<p class="hint">Výška se přizpůsobuje automaticky. Když registrační systém neodpovídá, zobrazí se místo formuláře náhradní hlášení. Vložení je povolené jen z webů uvedených v nastavení výše.</p>
</section>
