<?php
use Bkl\Admin\RegAdmin;
use Bkl\Domain\RegistrationService;
use Bkl\Util;

$hid = static fn (): string => '<input type="hidden" name="csrf" value="' . Util::e($csrf) . '">';
$kc = static fn ($m): string => rtrim(rtrim(number_format(((int) $m) / 100, 2, '.', ''), '0'), '.');
$ro = $canEdit ? '' : ' disabled';
$consentLabel = ['podminky' => 'podmínky závodu', 'osobni_udaje' => 'zpracování os. údajů', 'verejna_startovka' => 'veřejná startovka', 'souhlas_rodice' => 'souhlas zák. zástupce'];
?>
<p><a href="<?= $e($adm('akce/' . $ev['id'] . '/registrace')) ?>">← Registrace: <?= $e(Util::tr($ev['name'])) ?></a></p>
<div class="row-between"><h1>Objednávka <?= $e($o['vs']) ?></h1><span class="badge"><?= $e(RegAdmin::PAY[$o['payment_status']] ?? $o['payment_status']) ?></span></div>

<section class="card">
  <dl class="kv">
    <dt>Kontakt</dt><dd><?= $e($o['contact_name']) ?> · <a href="mailto:<?= $e($o['contact_email']) ?>"><?= $e($o['contact_email']) ?></a> · <?= $e($o['contact_phone']) ?></dd>
    <dt>Vytvořeno</dt><dd><?= $e(Util::fmtDateTime($o['created_at'])) ?> (<?= $e($o['channel']) ?>)</dd>
    <dt>Startovné / sleva</dt><dd><?= $e($money((int) $o['amount_entry'])) ?> / −<?= $e($money((int) $o['amount_discount'])) ?></dd>
    <dt>Produkty</dt><dd><?= $e($money((int) $o['amount_products'])) ?></dd>
    <dt>Charita</dt><dd><?= $e($money((int) $o['amount_charity'])) ?></dd>
    <dt>Celkem / zaplaceno</dt><dd><b><?= $e($money((int) $o['amount_total'])) ?></b> / <b><?= $e($money((int) $o['amount_paid'])) ?></b></dd>
    <dt>Splatnost</dt><dd><?= $e(Util::fmtDate($o['due_date'])) ?><?= $o['reminded_at'] ? ' · upomínka ' . $e(Util::fmtDateTime($o['reminded_at'])) : '' ?></dd>
    <dt>Účet pro platbu</dt><dd><?= $e($o['pay_account']) ?></dd>
    <dt>Odkaz závodníka</dt><dd><a href="<?= $e($url('moje/' . $o['public_token'])) ?>" target="_blank">otevřít stránku registrace ↗</a></dd>
    <?php if ($o['note']): ?><dt>Poznámka závodníka</dt><dd><?= nl2br($e($o['note'])) ?></dd><?php endif; ?>
  </dl>
  <form method="post" class="inline-row"><?= $hid() ?><fieldset<?= $ro ?>>
    <button name="action" value="resend" class="btn small secondary">Znovu poslat e-mail (platební údaje / potvrzení)</button></fieldset></form>
</section>

<?php foreach ($regs as $r): ?>
<section class="card">
  <div class="row-between"><h2><?= $e($r['first_name'] . ' ' . $r['last_name']) ?></h2><span class="badge s-<?= $e($r['status']) ?>"><?= $e(RegAdmin::STATUSES[$r['status']] ?? $r['status']) ?></span></div>
  <p class="hint">Cena: základ <?= $e($money((int) $r['price_base'])) ?>, věková sleva −<?= $e($money((int) $r['age_discount'])) ?>, kupon <?= $e($r['coupon_code'] ?: '—') ?> −<?= $e($money((int) $r['coupon_discount'])) ?>, produkty <?= $e($money((int) $r['price_products'])) ?> → <b><?= $e($money((int) $r['price_final'])) ?></b>
  · kategorie <?= $e($r['cat_code'] ?: '—') ?> · kód prezence <code><?= $e($r['checkin_code']) ?></code><?= $r['checked_in_at'] ? ' · prezence ' . $e(Util::fmtDateTime($r['checked_in_at'])) : '' ?></p>
  <?php if (!empty($values[(int) $r['id']])): ?><p>Doplňující údaje: <?php foreach ($values[(int) $r['id']] as $v): ?><span class="pill"><?= $e(Util::tr($v['label'])) ?>: <b><?= $e($v['value_text']) ?></b><?= (int) $v['surcharge'] ? ' (+' . $e($money((int) $v['surcharge'])) . ')' : '' ?></span> <?php endforeach; ?></p><?php endif; ?>
  <p class="hint">Souhlasy: <?php foreach ($consents[(int) $r['id']] ?? [] as $c): ?><?= $e($consentLabel[$c['type']] ?? $c['type']) ?> (<?= $e(Util::fmtDateTime($c['accepted_at'])) ?>, verze <?= $e($c['text_version']) ?>); <?php endforeach; ?></p>

  <?php if (!$r['anonymized_at']): ?>
  <details><summary>Upravit údaje</summary>
  <form method="post"><?= $hid() ?><input type="hidden" name="reg_id" value="<?= (int) $r['id'] ?>"><fieldset<?= $ro ?>>
    <div class="grid3">
      <div class="field"><label>Jméno<input name="first_name" value="<?= $e($r['first_name']) ?>"></label></div>
      <div class="field"><label>Příjmení<input name="last_name" value="<?= $e($r['last_name']) ?>"></label></div>
      <div class="field"><label>Datum narození (RRRR-MM-DD)<input name="birth_date" value="<?= $e($r['birth_date']) ?>"></label></div>
      <div class="field"><label>Pohlaví<select name="gender"><option value="M">muž</option><option value="Z" <?= $r['gender'] === 'Z' ? 'selected' : '' ?>>žena</option></select></label></div>
      <div class="field"><label>E-mail<input name="email" value="<?= $e($r['email']) ?>"></label></div>
      <div class="field"><label>Telefon<input name="phone" value="<?= $e($r['phone']) ?>"></label></div>
      <div class="field"><label>Klub / město<input name="club" value="<?= $e($r['club']) ?>"></label></div>
      <div class="field"><label>Země<select name="country"><?php foreach (RegistrationService::COUNTRIES as $k => $v): ?><option value="<?= $k ?>" <?= $r['country'] === $k ? 'selected' : '' ?>><?= $e($v) ?></option><?php endforeach; ?></select></label></div>
      <div class="field"><label>Trať<select name="race_id"><?php foreach ($races as $ra): ?><option value="<?= (int) $ra['id'] ?>" <?= (int) $ra['id'] === (int) $r['race_id'] ? 'selected' : '' ?>><?= $e(Util::tr($ra['name'])) ?></option><?php endforeach; ?></select></label></div>
      <div class="field"><label>Startovní číslo<input name="bib" value="<?= $e($r['bib']) ?>" inputmode="numeric"></label></div>
      <div class="field"><label>Zákonný zástupce<input name="guardian_name" value="<?= $e($r['guardian_name']) ?>"></label></div>
    </div>
    <button name="action" value="reg_save" class="btn small primary">Uložit údaje</button>
    <p class="hint">Změna údajů neodesílá e-mail automaticky; případně použijte „Znovu poslat e-mail“.</p>
  </fieldset></form>
  </details>
  <div class="inline-row">
    <form method="post" class="inline"><?= $hid() ?><input type="hidden" name="reg_id" value="<?= (int) $r['id'] ?>"><fieldset<?= $ro ?>>
      Stav: <select name="status"><?php foreach (['ceka_na_platbu', 'potvrzena', 'ke_kontrole', 'zrusena'] as $s): ?><option value="<?= $s ?>" <?= $r['status'] === $s ? 'selected' : '' ?>><?= $e(RegAdmin::STATUSES[$s]) ?></option><?php endforeach; ?></select>
      <button name="action" value="reg_status" class="btn small">Změnit stav</button></fieldset></form>
    <form method="post" class="inline"><?= $hid() ?><input type="hidden" name="reg_id" value="<?= (int) $r['id'] ?>"><fieldset<?= $ro ?>>
      Cena: <input name="price_final" value="<?= $e($kc($r['price_final'])) ?>" class="w5"> Kč <input name="reason" placeholder="důvod" class="w10">
      <button name="action" value="price" class="btn small">Upravit cenu</button></fieldset></form>
  </div>
  <details class="danger"><summary>Výmaz osobních údajů na žádost (GDPR)</summary>
    <form method="post"><?= $hid() ?><input type="hidden" name="reg_id" value="<?= (int) $r['id'] ?>"><fieldset<?= $ro ?>>
      <p class="hint">Nevratně smaže jméno, kontakty a doplňující údaje závodníka. Platby a částky zůstanou kvůli účetnictví. Pro potvrzení napište SMAZAT.</p>
      <input name="confirm" class="w10" autocomplete="off"> <button name="action" value="anonymize" class="btn small secondary">Vymazat údaje</button></fieldset></form>
  </details>
  <?php else: ?><p class="hint">Osobní údaje vymazány <?= $e(Util::fmtDateTime($r['anonymized_at'])) ?>.</p><?php endif; ?>
</section>
<?php endforeach; ?>

<section class="card">
  <h2>Platby</h2>
  <?php if ($payments): ?><div class="tbl-wrap"><table class="tbl compact"><thead><tr><th>Datum</th><th>Zdroj</th><th class="r">Částka</th><th>Od koho</th><th>Zpráva</th><th>Zadal</th></tr></thead><tbody>
  <?php foreach ($payments as $p): ?><tr><td><?= $e(Util::fmtDate($p['received_at'])) ?></td><td><?= $e($p['source']) ?></td><td class="r"><?= $e($money((int) $p['amount'])) ?></td><td><?= $e(trim($p['counter_name'] . ' ' . $p['counter_account'])) ?></td><td><?= $e($p['message']) ?></td><td><small><?= $e($p['created_by']) ?></small></td></tr><?php endforeach; ?>
  </tbody></table></div><?php else: ?><p class="hint">Zatím žádná platba.</p><?php endif; ?>
  <?php foreach ($refunds as $rf): ?><p class="hint">Vráceno <?= $e($money((int) $rf['amount'])) ?> – <?= $e($rf['reason']) ?> (<?= $e($rf['created_by']) ?>, <?= $e(Util::fmtDateTime($rf['created_at'])) ?>)</p><?php endforeach; ?>
  <form method="post" class="inline-row"><?= $hid() ?><fieldset<?= $ro ?>>
    Přijatá platba: <input name="amount" class="w5" placeholder="Kč"> <select name="source"><option value="hotovost">hotovost</option><option value="rucne">převod (ručně potvrzený)</option></select>
    <input name="note" placeholder="poznámka" class="w10"> <button name="action" value="payment_add" class="btn small primary">Zaevidovat platbu</button></fieldset></form>
  <form method="post" class="inline-row"><?= $hid() ?><fieldset<?= $ro ?>>
    Vrácení peněz: <input name="amount" class="w5" placeholder="Kč"> <input name="reason" placeholder="důvod (povinné)" class="w20">
    <label class="check inline"><input type="checkbox" name="notify" value="1" checked> poslat e-mail</label>
    <button name="action" value="refund" class="btn small secondary" data-confirm="Zaevidovat vrácení peněz? Peníze je třeba odeslat z banky ručně.">Zaevidovat vrácení</button></fieldset></form>
  <form method="post" class="inline-row"><?= $hid() ?><fieldset<?= $ro ?>>
    <label class="check inline"><input type="checkbox" name="notify" value="1" checked> poslat e-mail o zrušení</label>
    <button name="action" value="cancel_order" class="btn small secondary" data-confirm="Zrušit všechny registrace v objednávce?">Zrušit celou objednávku</button></fieldset></form>
</section>

<section class="card">
  <h2>Interní poznámka</h2>
  <form method="post"><?= $hid() ?><fieldset<?= $ro ?>><textarea name="internal_note" rows="3"><?= $e($o['internal_note']) ?></textarea><button name="action" value="note" class="btn small">Uložit poznámku</button></fieldset></form>
</section>

<section class="card">
  <h2>E-maily</h2>
  <ul class="log"><?php foreach ($mails as $m): ?><li class="<?= $m['status'] === 'chyba' ? 'lv-chyba' : '' ?>"><small><?= $e(Util::fmtDateTime($m['created_at'])) ?></small> <?= $e($m['to_email']) ?> – <?= $e($m['subject']) ?> – <b><?= $e($m['status']) ?></b><?= $m['last_error'] ? ' (' . $e($m['last_error']) . ')' : '' ?></li><?php endforeach; ?></ul>
</section>

<section class="card">
  <h2>Historie změn</h2>
  <ul class="log"><?php foreach ($audit as $a): ?><li><small><?= $e(Util::fmtDateTime($a['created_at'])) ?></small> <b><?= $e($a['action']) ?></b> <?= $e($a['actor']) ?> <small><?= $e(mb_substr((string) $a['new_value'], 0, 200)) ?></small></li><?php endforeach; ?></ul>
</section>
