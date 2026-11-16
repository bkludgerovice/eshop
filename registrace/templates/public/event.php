<?php
use Bkl\Domain\Pricing;
use Bkl\Domain\RegistrationService;
use Bkl\Util;

/** @var array $ev @var array $av @var ?array $old @var array $errors */
$now = Util::now();
$runnersOld = $old && is_array($old['runners'] ?? null) ? array_values($old['runners']) : [[]];
$err = static fn (string $k): string => isset($errors[$k]) ? '<p class="err">' . Util::e($errors[$k]) . '</p>' : '';
$cls = static fn (string $k): string => isset($errors[$k]) ? ' invalid' : '';
$charity = $ev['charity'];
$months = ['leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];
$racePrice = static function (array $race) use ($ev, $now): ?int {
    $t = Pricing::pickTier($ev['tiers'], (int) $race['id'], $now);
    return $t ? (int) $t['amount'] : null;
};

$runner = static function (string $i, array $r) use ($ev, $e, $err, $cls, $months, $racePrice, $occupancy, $errors): string {
    $v = static fn (string $k): string => is_scalar($r[$k] ?? null) ? (string) $r[$k] : '';
    $bd = $v('birth_date');
    [$by, $bm, $bdd] = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $bd, $m) ? [$m[1], (int) $m[2], (int) $m[3]] : [$v('bd_y'), (int) $v('bd_m'), (int) $v('bd_d')];
    $p = "runners[$i]";
    $k = "runners.$i.";
    ob_start(); ?>
    <fieldset class="runner card" data-index="<?= $e($i) ?>">
      <legend><span class="runner-title">Závodník <span class="runner-no"><?= is_numeric($i) ? (int) $i + 1 : '' ?></span></span>
        <button type="button" class="link remove-runner" hidden>Odebrat</button></legend>

      <div class="field<?= $cls($k . 'race_id') ?>">
        <span class="label">Trať *</span>
        <div class="races">
        <?php foreach ($ev['races'] as $race):
            $price = $racePrice($race);
            $full = $race['capacity'] !== null && ($occupancy['races'][(int) $race['id']] ?? 0) >= (int) $race['capacity']; ?>
          <label class="race<?= $full ? ' full' : '' ?>">
            <input type="radio" name="<?= $p ?>[race_id]" value="<?= (int) $race['id'] ?>" <?= (int) $v('race_id') === (int) $race['id'] || count($ev['races']) === 1 ? 'checked' : '' ?> <?= $full ? 'disabled' : '' ?> required>
            <span><b><?= $e(Util::tr($race['name'])) ?></b><small><?= $full ? 'obsazeno' : ($price !== null ? $e(Util::money($price)) : '') ?></small></span>
          </label>
        <?php endforeach; ?>
        </div>
        <?= $err($k . 'race_id') ?>
      </div>

      <div class="grid2">
        <div class="field<?= $cls($k . 'first_name') ?>"><label>Jméno *<input name="<?= $p ?>[first_name]" value="<?= $e($v('first_name')) ?>" autocomplete="<?= $i === '0' ? 'given-name' : 'off' ?>" required maxlength="100"></label><?= $err($k . 'first_name') ?></div>
        <div class="field<?= $cls($k . 'last_name') ?>"><label>Příjmení *<input name="<?= $p ?>[last_name]" value="<?= $e($v('last_name')) ?>" autocomplete="<?= $i === '0' ? 'family-name' : 'off' ?>" required maxlength="100"></label><?= $err($k . 'last_name') ?></div>
      </div>

      <div class="field<?= $cls($k . 'birth_date') ?>">
        <span class="label">Datum narození *</span>
        <div class="bd">
          <input class="bd-d" name="<?= $p ?>[bd_d]" value="<?= $bdd ?: '' ?>" inputmode="numeric" pattern="[0-9]*" maxlength="2" placeholder="den" aria-label="Den narození" required>
          <select class="bd-m" name="<?= $p ?>[bd_m]" aria-label="Měsíc narození" required>
            <option value="">měsíc</option>
            <?php foreach ($months as $mi => $mn): ?><option value="<?= $mi + 1 ?>" <?= $bm === $mi + 1 ? 'selected' : '' ?>><?= $mn ?></option><?php endforeach; ?>
          </select>
          <input class="bd-y" name="<?= $p ?>[bd_y]" value="<?= $e($by) ?>" inputmode="numeric" pattern="[0-9]*" maxlength="4" placeholder="rok" aria-label="Rok narození" required>
        </div>
        <?= $err($k . 'birth_date') ?>
      </div>

      <div class="field<?= $cls($k . 'gender') ?>">
        <span class="label">Pohlaví *</span>
        <div class="seg">
          <label><input type="radio" name="<?= $p ?>[gender]" value="M" <?= $v('gender') === 'M' ? 'checked' : '' ?> required><span>Muž</span></label>
          <label><input type="radio" name="<?= $p ?>[gender]" value="Z" <?= $v('gender') === 'Z' ? 'checked' : '' ?>><span>Žena</span></label>
        </div>
        <?= $err($k . 'gender') ?>
      </div>

      <div class="grid2">
        <div class="field<?= $cls($k . 'email') ?>"><label>E-mail *<input type="email" name="<?= $p ?>[email]" value="<?= $e($v('email')) ?>" autocomplete="<?= $i === '0' ? 'email' : 'off' ?>" inputmode="email" required maxlength="190"></label><?= $err($k . 'email') ?></div>
        <div class="field<?= $cls($k . 'phone') ?>"><label>Telefon<?= $i === '0' ? ' *' : '' ?><input type="tel" name="<?= $p ?>[phone]" value="<?= $e($v('phone')) ?>" autocomplete="<?= $i === '0' ? 'tel' : 'off' ?>" inputmode="tel" maxlength="40" <?= $i === '0' ? 'required' : '' ?>></label><?= $err($k . 'phone') ?></div>
      </div>

      <div class="grid2">
        <div class="field<?= $cls($k . 'club') ?>"><label>Klub nebo město *<input name="<?= $p ?>[club]" value="<?= $e($v('club')) ?>" required maxlength="150"></label><?= $err($k . 'club') ?></div>
        <div class="field"><label>Země
          <select name="<?= $p ?>[country]">
            <?php foreach (RegistrationService::COUNTRIES as $code => $name): ?><option value="<?= $code ?>" <?= ($v('country') ?: 'CZ') === $code ? 'selected' : '' ?>><?= $e($name) ?></option><?php endforeach; ?>
          </select></label></div>
      </div>

      <?php foreach ($ev['fields'] as $f):
          $fid = (int) $f['id'];
          $fname = $p . '[fields][' . $fid . ']';
          $fv = $r['fields'][$fid] ?? null;
          $req = (int) $f['required'] === 1; ?>
      <div class="field custom<?= $cls($k . 'f' . $fid) ?>"<?= $f['race_id'] !== null ? ' data-race="' . (int) $f['race_id'] . '"' : '' ?>>
        <?php if ($f['type'] === 'select'): ?>
          <label><?= $e(Util::tr($f['label'])) ?><?= $req ? ' *' : '' ?>
          <select name="<?= $fname ?>" <?= $req ? 'required' : '' ?>>
            <option value=""><?= $req ? 'vyberte…' : 'nechci' ?></option>
            <?php foreach ($f['options'] as $o): ?><option value="<?= (int) $o['id'] ?>" <?= (string) $fv === (string) $o['id'] ? 'selected' : '' ?>><?= $e(Util::tr($o['label'])) ?><?= (int) $o['surcharge'] ? ' (+' . $e(Util::money((int) $o['surcharge'])) . ')' : '' ?></option><?php endforeach; ?>
          </select></label>
        <?php elseif ($f['type'] === 'multiselect'): ?>
          <span class="label"><?= $e(Util::tr($f['label'])) ?><?= $req ? ' *' : '' ?></span>
          <?php foreach ($f['options'] as $o): ?>
            <label class="check"><input type="checkbox" name="<?= $fname ?>[]" value="<?= (int) $o['id'] ?>" <?= in_array((string) $o['id'], array_map('strval', (array) $fv), true) ? 'checked' : '' ?>> <?= $e(Util::tr($o['label'])) ?><?= (int) $o['surcharge'] ? ' (+' . $e(Util::money((int) $o['surcharge'])) . ')' : '' ?></label>
          <?php endforeach; ?>
        <?php elseif ($f['type'] === 'checkbox'): $sur = $f['options'] ? (int) $f['options'][0]['surcharge'] : 0; ?>
          <label class="check"><input type="checkbox" name="<?= $fname ?>" value="1" <?= $fv ? 'checked' : '' ?> <?= $req ? 'required' : '' ?>> <?= $e(Util::tr($f['label'])) ?><?= $sur ? ' (+' . $e(Util::money($sur)) . ')' : '' ?></label>
        <?php else: ?>
          <label><?= $e(Util::tr($f['label'])) ?><?= $req ? ' *' : '' ?>
          <input name="<?= $fname ?>" value="<?= $e(is_scalar($fv) ? $fv : '') ?>" type="<?= $f['type'] === 'number' ? 'text' : ($f['type'] === 'date' ? 'date' : 'text') ?>" <?= $f['type'] === 'number' ? 'inputmode="decimal"' : '' ?> <?= $req ? 'required' : '' ?> maxlength="1000"></label>
        <?php endif; ?>
        <?php if ($f['help']): ?><small class="hint"><?= $e(Util::tr($f['help'])) ?></small><?php endif; ?>
        <?= $err($k . 'f' . $fid) ?>
      </div>
      <?php endforeach; ?>

      <div class="guardian<?= $v('guardian_name') !== '' || isset($errors[$k . 'guardian_name']) || isset($errors[$k . 'guardian_consent']) ? '' : ' maybe' ?>" data-guardian-age="<?= (int) $ev['guardian_age'] ?>" data-event-date="<?= $e(substr((string) $ev['starts_at'], 0, 10)) ?>">
        <p class="hint">Závodník je v den závodu mladší <?= (int) $ev['guardian_age'] ?> let – vyplňte prosím údaje zákonného zástupce.</p>
        <div class="field<?= $cls($k . 'guardian_name') ?>"><label>Jméno a příjmení zákonného zástupce *<input name="<?= $p ?>[guardian_name]" value="<?= $e($v('guardian_name')) ?>" maxlength="200"></label><?= $err($k . 'guardian_name') ?></div>
        <div class="field<?= $cls($k . 'guardian_consent') ?>"><label class="check"><input type="checkbox" name="<?= $p ?>[guardian_consent]" value="1" <?= $v('guardian_consent') ? 'checked' : '' ?>> Jako zákonný zástupce souhlasím s účastí nezletilého v závodě a s podmínkami závodu.</label><?= $err($k . 'guardian_consent') ?></div>
      </div>

      <details class="coupon"<?= $v('coupon') !== '' ? ' open' : '' ?>>
        <summary>Mám slevový kód</summary>
        <div class="field<?= $cls($k . 'coupon') ?>"><label>Slevový kód<input name="<?= $p ?>[coupon]" value="<?= $e($v('coupon')) ?>" autocomplete="off" autocapitalize="characters" maxlength="40"></label><?= $err($k . 'coupon') ?><p class="err coupon-msg" hidden></p></div>
      </details>

      <p class="runner-price">Cena za závodníka: <b class="line-price">–</b> <span class="discount-note"></span></p>
    </fieldset>
    <?php return (string) ob_get_clean();
};
$desc = Util::tr($ev['description']);
?>
<article class="event">
  <header class="event-head">
    <?php if ($ev['logo_url']): ?><img class="event-logo" src="<?= $e($ev['logo_url']) ?>" alt=""><?php endif; ?>
    <div>
      <h1><?= $e(Util::tr($ev['name'])) ?></h1>
      <p class="meta"><?= $e(Util::fmtDateTime($ev['starts_at'])) ?><?= $ev['venue'] ? ' · ' . $e($ev['venue']) : '' ?></p>
    </div>
  </header>
  <?php if ($desc !== '' && !$embed): ?><div class="desc"><?= nl2br($e($desc)) ?></div><?php endif; ?>

  <ul class="facts">
    <?php foreach ($ev['races'] as $race): $price = $racePrice($race); ?>
      <li><b><?= $e(Util::tr($race['name'])) ?></b> <?= $price !== null ? $e(Util::money($price)) : '' ?></li>
    <?php endforeach; ?>
    <?php if ($av['free'] !== null): ?><li class="free">Volná místa: <b><?= (int) $av['free'] ?></b></li><?php endif; ?>
    <?php if ((int) $ev['public_list_enabled'] === 1): ?><li><a href="<?= $e($url($ev['slug'] . '/startovka' . ($embed ? '?embed=1' : ''))) ?>">Startovní listina</a></li><?php endif; ?>
  </ul>
  <?php if ($ev['age_rules']): ?>
    <p class="hint">Slevy: <?= $e(implode(', ', array_map(static fn ($a) => $a['name'] . ' ' . $a['percent'] . ' %', $ev['age_rules']))) ?> (věk podle ročníku narození). Slevy se nesčítají.</p>
  <?php endif; ?>

<?php if (!$av['open']): ?>
  <div class="card notice"><p><b><?= $e($av['reason']) ?></b></p>
  <?php if ($ev['onsite_enabled'] && $av['free'] !== 0 && in_array($ev['status'], ['otevreno', 'uzavreno'], true)): ?><p>Pokud zůstanou volná místa, bude možné se přihlásit i na místě v den závodu.</p><?php endif; ?></div>
<?php else: ?>
  <form method="post" action="<?= $e($url($ev['slug'])) ?>" id="regform" novalidate data-price-url="<?= $e($url($ev['slug'] . '/cena')) ?>" data-max="<?= (int) $ev['max_runners_per_order'] ?>">
    <input type="hidden" name="form_token" value="<?= $e($token) ?>">
    <input type="hidden" name="embed" value="<?= $embed ? '1' : '0' ?>">
    <div class="hp" aria-hidden="true"><label>Web <input name="website" tabindex="-1" autocomplete="off"></label></div>
    <?php if (isset($errors['form'])): ?><div class="alert" role="alert"><?= $e($errors['form']) ?></div><?php endif; ?>
    <?= $err('runners') ?>

    <div id="runners">
      <?php foreach ($runnersOld as $i => $r) { echo $runner((string) $i, is_array($r) ? $r : []); } ?>
    </div>
    <template id="runner-tpl"><?= $runner('__I__', []) ?></template>
    <button type="button" class="btn secondary" id="add-runner">+ Přidat dalšího závodníka</button>
    <p class="hint">Každý závodník dostane vlastní registraci, platí se jednou platbou za celou přihlášku.</p>

    <?php if ($charity): $presets = array_filter(array_map('intval', explode(',', (string) $charity['preset_amounts']))); $cv = (string) ($old['charity'] ?? '0'); ?>
    <section class="card charity">
      <h2>Dobrovolný příspěvek</h2>
      <p><?= $e(Util::tr($charity['purpose'])) ?></p>
      <div class="seg wrap">
        <label><input type="radio" name="charity_pick" value="0" <?= $cv === '0' || $cv === '' ? 'checked' : '' ?>><span>Bez příspěvku</span></label>
        <?php foreach ($presets as $pr): ?><label><input type="radio" name="charity_pick" value="<?= $pr ?>" <?= $cv === (string) $pr ? 'checked' : '' ?>><span><?= $pr ?> Kč</span></label><?php endforeach; ?>
        <?php if ((int) $charity['allow_custom'] === 1): ?><label><input type="radio" name="charity_pick" value="vlastni" <?= $cv !== '' && $cv !== '0' && !in_array((int) $cv, $presets, true) ? 'checked' : '' ?>><span>Jiná částka</span></label><?php endif; ?>
      </div>
      <div class="field<?= $cls('charity') ?>"><label>Částka příspěvku (Kč)<input name="charity" id="charity" value="<?= $e($cv) ?>" inputmode="numeric" maxlength="7"></label><?= $err('charity') ?></div>
    </section>
    <?php endif; ?>

    <div class="field"><label>Poznámka pro pořadatele<textarea name="note" rows="2" maxlength="1000"><?= $e($old['note'] ?? '') ?></textarea></label></div>

    <section class="card consents">
      <div class="field<?= $cls('consent_terms') ?>"><label class="check"><input type="checkbox" name="consent_terms" value="1" <?= !empty($old['consent_terms']) ? 'checked' : '' ?> required> Souhlasím s <?= $ev['terms_url'] ? '<a href="' . $e($ev['terms_url']) . '" target="_blank" rel="noopener">podmínkami závodu</a>' : 'podmínkami závodu' ?> *</label><?= $err('consent_terms') ?></div>
      <div class="field<?= $cls('consent_gdpr') ?>"><label class="check"><input type="checkbox" name="consent_gdpr" value="1" <?= !empty($old['consent_gdpr']) ? 'checked' : '' ?> required> Souhlasím se <?= $ev['gdpr_url'] ? '<a href="' . $e($ev['gdpr_url']) . '" target="_blank" rel="noopener">zpracováním osobních údajů</a>' : 'zpracováním osobních údajů' ?> pro účely registrace a organizace závodu. Beru na vědomí, že <b>jméno, ročník, klub/město a trať budou uvedeny ve veřejné startovní listině</b> a ve výsledcích. *</label><?= $err('consent_gdpr') ?></div>
    </section>

    <div class="summary card">
      <p>Celkem k úhradě: <b id="total">–</b></p>
      <p class="hint">Platba převodem nebo QR kódem do <?= (int) $ev['payment_due_days'] ?> dnů. Platební údaje se zobrazí po odeslání a přijdou i e-mailem.</p>
      <button type="submit" class="btn primary big">Odeslat registraci</button>
    </div>
  </form>
<?php endif; ?>
</article>
<script src="<?= $e($asset('form.js')) ?>" defer></script>
