<?php
declare(strict_types=1);

namespace Bkl\Domain;

use Bkl\Db;
use Bkl\Log;
use Bkl\Util;
use Bkl\ValidationException;

/**
 * Vytvoření objednávky s jedním nebo více závodníky.
 *
 * Kapacita je chráněná zámkem řádku akce (SELECT … FOR UPDATE) – dvě současné registrace
 * na poslední místo se zpracují postupně a druhá dostane hlášku „kapacita naplněna“.
 */
final class RegistrationService
{
    public const ACTIVE = ['ceka_na_platbu', 'potvrzena', 'ke_kontrole'];
    public const CONSENT_VERSION = '2026-11';

    public const COUNTRIES = [
        'CZ' => 'Česko', 'SK' => 'Slovensko', 'PL' => 'Polsko', 'DE' => 'Německo', 'AT' => 'Rakousko',
        'HU' => 'Maďarsko', 'UA' => 'Ukrajina', 'GB' => 'Velká Británie', 'XX' => 'Jiná země',
    ];

    /** @var callable|null fn(int $orderId): void – odeslání e-mailů po uložení */
    private $afterCreate;

    public function __construct(private Db $db, private EventRepo $events, private Log $log, ?callable $afterCreate = null)
    {
        $this->afterCreate = $afterCreate;
    }

    /**
     * Normalizace a kontrola vstupu z formuláře (bez zápisu do DB).
     * @param array<string,mixed> $event
     * @param array<string,mixed> $in
     * @return array{runners:list<array<string,mixed>>,charity:int,note:string,consents:array<string,bool>}
     */
    public function validate(array $event, array $in, string $channel = 'online'): array
    {
        $err = [];
        $runnersIn = is_array($in['runners'] ?? null) ? array_values($in['runners']) : [];
        if (!$runnersIn) {
            throw new ValidationException(['runners' => 'Přidejte alespoň jednoho závodníka.']);
        }
        $max = (int) $event['max_runners_per_order'];
        if (count($runnersIn) > $max) {
            throw new ValidationException(['runners' => "V jedné přihlášce může být nejvýše $max závodníků."]);
        }
        $races = [];
        foreach ($event['races'] as $r) {
            $races[(int) $r['id']] = $r;
        }
        $eventDate = Util::dt((string) $event['starts_at']) ?? Util::now();
        $year = (int) $event['year'];
        $runners = [];
        $seen = [];
        foreach ($runnersIn as $i => $r) {
            $p = "runners.$i.";
            $r = is_array($r) ? $r : [];
            if (trim((string) ($r['birth_date'] ?? '')) === '' && isset($r['bd_y'])) {
                // formulář posílá den / měsíc / rok zvlášť
                $y = (int) preg_replace('/\D/', '', (string) $r['bd_y']);
                $r['birth_date'] = sprintf('%04d-%02d-%02d', $y, (int) ($r['bd_m'] ?? 0), (int) preg_replace('/\D/', '', (string) ($r['bd_d'] ?? '')));
            }
            $x = [
                'race_id' => (int) ($r['race_id'] ?? 0),
                'first_name' => Util::clean($r['first_name'] ?? '', 100),
                'last_name' => Util::clean($r['last_name'] ?? '', 100),
                'birth_date' => Util::clean($r['birth_date'] ?? '', 10),
                'gender' => strtoupper(Util::clean($r['gender'] ?? '', 1)),
                'email' => mb_strtolower(Util::clean($r['email'] ?? '', 190)),
                'phone' => preg_replace('/[^\d+ ]/', '', Util::clean($r['phone'] ?? '', 40)) ?? '',
                'club' => Util::clean($r['club'] ?? '', 150),
                'country' => strtoupper(Util::clean($r['country'] ?? 'CZ', 2)),
                'coupon' => strtoupper(preg_replace('/\s+/', '', Util::clean($r['coupon'] ?? '', 40)) ?? ''),
                'guardian_name' => Util::clean($r['guardian_name'] ?? '', 200),
                'guardian_consent' => !empty($r['guardian_consent']),
                'fields' => [],
            ];
            if (!isset($races[$x['race_id']])) {
                $err[$p . 'race_id'] = 'Vyberte trať.';
            }
            if (mb_strlen($x['first_name']) < 1) {
                $err[$p . 'first_name'] = 'Vyplňte jméno.';
            }
            if (mb_strlen($x['last_name']) < 2) {
                $err[$p . 'last_name'] = 'Vyplňte příjmení.';
            }
            $bd = \DateTimeImmutable::createFromFormat('!Y-m-d', $x['birth_date']);
            $age = null;
            if (!$bd || $bd->format('Y-m-d') !== $x['birth_date'] || (int) $bd->format('Y') < 1900 || $bd > $eventDate) {
                $err[$p . 'birth_date'] = 'Vyplňte platné datum narození.';
            } else {
                $age = Pricing::ageByYear($x['birth_date'], $year);
            }
            if (!in_array($x['gender'], ['M', 'Z'], true)) {
                $err[$p . 'gender'] = 'Vyberte pohlaví.';
            }
            if (!Util::isEmail($x['email'])) {
                $err[$p . 'email'] = 'Zadejte platný e-mail.';
            }
            if ($i === 0 && strlen(preg_replace('/\D/', '', $x['phone']) ?? '') < 9) {
                $err[$p . 'phone'] = 'Zadejte telefon (alespoň 9 číslic).';
            }
            if ($x['club'] === '') {
                $err[$p . 'club'] = 'Vyplňte klub nebo město.';
            }
            if (!isset(self::COUNTRIES[$x['country']])) {
                $x['country'] = 'XX';
            }
            // Věkové limity tratě (podle ročníku)
            if ($age !== null && isset($races[$x['race_id']])) {
                $race = $races[$x['race_id']];
                if ($race['min_age'] !== null && $age < (int) $race['min_age']) {
                    $err[$p . 'race_id'] = 'Na tuto trať je minimální věk ' . $race['min_age'] . ' let (podle ročníku).';
                }
                if ($race['max_age'] !== null && $age > (int) $race['max_age']) {
                    $err[$p . 'race_id'] = 'Na tuto trať je maximální věk ' . $race['max_age'] . ' let (podle ročníku).';
                }
                if ($channel === 'online') {
                    $now = Util::now();
                    if ($race['reg_opens_at'] && $now < Util::dt($race['reg_opens_at'])) {
                        $err[$p . 'race_id'] = 'Registrace na tuto trať ještě nejsou otevřené.';
                    }
                    if ($race['reg_closes_at'] && $now > Util::dt($race['reg_closes_at'])) {
                        $err[$p . 'race_id'] = 'Registrace na tuto trať jsou uzavřené.';
                    }
                }
            }
            // Nezletilí – souhlas zákonného zástupce (přesný věk v den akce)
            $x['minor'] = false;
            if ($bd && !isset($err[$p . 'birth_date']) && Pricing::exactAge($x['birth_date'], $eventDate) < (int) $event['guardian_age']) {
                $x['minor'] = true;
                if (mb_strlen($x['guardian_name']) < 3) {
                    $err[$p . 'guardian_name'] = 'U nezletilého vyplňte jméno zákonného zástupce.';
                }
                if (!$x['guardian_consent']) {
                    $err[$p . 'guardian_consent'] = 'Zákonný zástupce musí potvrdit souhlas.';
                }
            }
            // Vlastní pole
            $fin = is_array($r['fields'] ?? null) ? $r['fields'] : [];
            foreach ($event['fields'] as $f) {
                if ($f['race_id'] !== null && (int) $f['race_id'] !== $x['race_id']) {
                    continue;
                }
                $fid = (int) $f['id'];
                $raw = $fin[$fid] ?? $fin['f' . $fid] ?? null;
                $label = Util::tr($f['label']);
                $opts = [];
                foreach ($f['options'] as $o) {
                    $opts[(int) $o['id']] = $o;
                }
                $vals = [];
                switch ($f['type']) {
                    case 'select':
                        $oid = (int) $raw;
                        if ($oid && isset($opts[$oid])) {
                            $vals[] = ['option_id' => $oid, 'value_text' => Util::tr($opts[$oid]['label']), 'surcharge' => (int) $opts[$oid]['surcharge']];
                        } elseif ($raw !== null && $raw !== '' && $raw !== '0') {
                            $err[$p . 'f' . $fid] = "Neplatná volba v poli „{$label}“.";
                        }
                        break;
                    case 'multiselect':
                        foreach ((array) $raw as $oid) {
                            $oid = (int) $oid;
                            if (isset($opts[$oid])) {
                                $vals[] = ['option_id' => $oid, 'value_text' => Util::tr($opts[$oid]['label']), 'surcharge' => (int) $opts[$oid]['surcharge']];
                            }
                        }
                        break;
                    case 'checkbox':
                        if (!empty($raw)) {
                            $sur = $f['options'] ? (int) $f['options'][0]['surcharge'] : 0;
                            $vals[] = ['option_id' => $f['options'] ? (int) $f['options'][0]['id'] : null, 'value_text' => 'ano', 'surcharge' => $sur];
                        }
                        break;
                    case 'number':
                        $v = Util::clean($raw ?? '', 20);
                        if ($v !== '' && !is_numeric(str_replace(',', '.', $v))) {
                            $err[$p . 'f' . $fid] = "Pole „{$label}“ musí být číslo.";
                        } elseif ($v !== '') {
                            $vals[] = ['option_id' => null, 'value_text' => $v, 'surcharge' => 0];
                        }
                        break;
                    case 'date':
                        $v = Util::clean($raw ?? '', 10);
                        if ($v !== '' && !\DateTimeImmutable::createFromFormat('!Y-m-d', $v)) {
                            $err[$p . 'f' . $fid] = "Pole „{$label}“ musí být datum.";
                        } elseif ($v !== '') {
                            $vals[] = ['option_id' => null, 'value_text' => $v, 'surcharge' => 0];
                        }
                        break;
                    default:
                        $v = Util::clean($raw ?? '', 1000);
                        if ($v !== '') {
                            $vals[] = ['option_id' => null, 'value_text' => $v, 'surcharge' => 0];
                        }
                }
                if ((int) $f['required'] === 1 && !$vals && !isset($err[$p . 'f' . $fid])) {
                    $err[$p . 'f' . $fid] = "Vyplňte pole „{$label}“.";
                }
                foreach ($vals as $v) {
                    $v['field_id'] = $fid;
                    $v['is_product'] = (int) $f['is_product'] === 1 || $v['surcharge'] !== 0;
                    $x['fields'][] = $v;
                }
            }
            $key = Util::fold($x['first_name'] . '|' . $x['last_name'] . '|' . $x['birth_date']);
            if (isset($seen[$key])) {
                $err[$p . 'last_name'] = 'Tento závodník je v přihlášce dvakrát.';
            }
            $seen[$key] = true;
            $runners[] = $x;
        }

        $charity = 0;
        if (!empty($event['charity'])) {
            $c = Util::parseMoney((string) ($in['charity'] ?? '0'));
            if ($c === null || $c < 0 || $c > 10_000_000) {
                $err['charity'] = 'Neplatná výše příspěvku.';
            } else {
                $charity = (int) (round($c / 100) * 100);
            }
        }
        $consents = [
            'podminky' => !empty($in['consent_terms']),
            'osobni_udaje' => !empty($in['consent_gdpr']),
        ];
        if ($channel === 'online') {
            if (!$consents['podminky']) {
                $err['consent_terms'] = 'Pro registraci je nutné souhlasit s podmínkami závodu.';
            }
            if (!$consents['osobni_udaje']) {
                $err['consent_gdpr'] = 'Pro registraci je nutný souhlas se zpracováním osobních údajů.';
            }
        }
        if ($err) {
            throw new ValidationException($err);
        }
        return ['runners' => $runners, 'charity' => $charity, 'note' => Util::clean($in['note'] ?? '', 1000), 'consents' => $consents];
    }

    /**
     * Výpočet cen (pro náhled ve formuláři i pro uložení).
     * @param array<string,mixed> $event
     * @param list<array<string,mixed>> $runners
     * @param array<string,array<string,mixed>> $coupons kód => řádek kuponu
     * @return array{lines:list<array<string,mixed>>,entry:int,products:int,discount:int,errors:array<string,string>}
     */
    public function price(array $event, array $runners, array $coupons, \DateTimeImmutable $now, string $channel): array
    {
        $lines = [];
        $errors = [];
        $entry = $products = $discount = 0;
        $useCount = [];
        foreach ($runners as $i => $r) {
            $tier = Pricing::pickTier($event['tiers'], (int) $r['race_id'], $now, $channel);
            if (!$tier) {
                $errors["runners.$i.race_id"] = 'Pro tuto trať teď není nastavená cena – kontaktujte pořadatele.';
            }
            $coupon = null;
            if ($r['coupon'] !== '') {
                $c = $coupons[$r['coupon']] ?? null;
                $msg = self::couponProblem($c, $now);
                if ($msg === null && $c['max_uses'] !== null) {
                    $useCount[$r['coupon']] = ($useCount[$r['coupon']] ?? 0) + 1;
                    if ((int) $c['used_count'] + $useCount[$r['coupon']] > (int) $c['max_uses']) {
                        $msg = 'Slevový kód už byl použit.';
                    }
                }
                if ($msg !== null) {
                    $errors["runners.$i.coupon"] = $msg;
                } else {
                    $coupon = $c;
                }
            }
            $prod = array_sum(array_map(static fn ($v) => (int) $v['surcharge'], $r['fields']));
            $age = Pricing::ageByYear($r['birth_date'], (int) $event['year']);
            $q = Pricing::quote($tier, $age, $event['age_rules'], $coupon, $prod, (int) $event['discount_applies_to_products'] === 1);
            $q['tier'] = $tier;
            $q['coupon'] = $q['coupon_discount'] > 0 || ($coupon && $q['discount_source'] === 'kupon') ? $coupon : null;
            $lines[] = $q;
            $entry += $q['base'];
            $products += $q['products'];
            $discount += $q['age_discount'] + $q['coupon_discount'];
        }
        return ['lines' => $lines, 'entry' => $entry, 'products' => $products, 'discount' => $discount, 'errors' => $errors];
    }

    /** @param array<string,mixed>|null $c */
    public static function couponProblem(?array $c, \DateTimeImmutable $now): ?string
    {
        if (!$c || (int) $c['active'] !== 1) {
            return 'Neplatný slevový kód.';
        }
        $at = $now->format('Y-m-d H:i:s');
        if (($c['valid_from'] && $c['valid_from'] > $at) || ($c['valid_to'] && $c['valid_to'] < $at)) {
            return 'Platnost slevového kódu vypršela.';
        }
        if ($c['max_uses'] !== null && (int) $c['used_count'] >= (int) $c['max_uses']) {
            return 'Slevový kód už byl použit.';
        }
        return null;
    }

    /**
     * Náhled ceny pro formulář (bez uložení).
     * @param array<string,mixed> $event
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public function preview(array $event, array $in, string $channel = 'online'): array
    {
        $runners = [];
        foreach ((array) ($in['runners'] ?? []) as $r) {
            $r = (array) $r;
            $bd = (string) ($r['birth_date'] ?? '');
            if ($bd === '' && !empty($r['bd_y'])) {
                $bd = sprintf('%04d-%02d-%02d', (int) $r['bd_y'], max(1, (int) ($r['bd_m'] ?? 1)), max(1, (int) ($r['bd_d'] ?? 1)));
            }
            $fields = [];
            foreach ($event['fields'] as $f) {
                $raw = ((array) ($r['fields'] ?? []))[(int) $f['id']] ?? null;
                foreach ($f['options'] as $o) {
                    $hit = match ($f['type']) {
                        'select' => (int) $raw === (int) $o['id'],
                        'multiselect' => in_array((string) $o['id'], array_map('strval', (array) $raw), true),
                        'checkbox' => !empty($raw) && $o === $f['options'][0],
                        default => false,
                    };
                    if ($hit) {
                        $fields[] = ['surcharge' => (int) $o['surcharge']];
                    }
                }
            }
            $runners[] = [
                'race_id' => (int) ($r['race_id'] ?? 0),
                'birth_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $bd) ? $bd : $event['year'] - 30 . '-01-01',
                'coupon' => strtoupper(preg_replace('/\s+/', '', (string) ($r['coupon'] ?? '')) ?? ''),
                'fields' => $fields,
            ];
        }
        $coupons = $this->loadCoupons((int) $event['id'], array_column($runners, 'coupon'), false);
        $p = $this->price($event, $runners, $coupons, Util::now(), $channel);
        $charity = !empty($event['charity']) ? max(0, (int) (Util::parseMoney((string) ($in['charity'] ?? '0')) ?? 0)) : 0;
        return [
            'lines' => array_map(static fn ($l) => [
                'base' => $l['base'], 'discount' => $l['age_discount'] + $l['coupon_discount'], 'products' => $l['products'],
                'final' => $l['final'], 'source' => $l['discount_source'], 'percent' => $l['age_percent'],
            ], $p['lines']),
            'charity' => $charity,
            'total' => array_sum(array_column($p['lines'], 'final')) + $charity,
            'errors' => array_filter($p['errors'], static fn ($k) => str_ends_with($k, '.coupon'), ARRAY_FILTER_USE_KEY),
        ];
    }

    /** @param list<string> $codes @return array<string,array<string,mixed>> */
    private function loadCoupons(int $eventId, array $codes, bool $lock): array
    {
        $codes = array_values(array_unique(array_filter($codes)));
        if (!$codes) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($codes), '?'));
        $rows = $this->db->all(
            "SELECT * FROM coupons WHERE event_id = ? AND UPPER(code) IN ($ph)" . ($lock ? ' FOR UPDATE' : ''),
            array_merge([$eventId], $codes)
        );
        $out = [];
        foreach ($rows as $c) {
            $out[strtoupper($c['code'])] = $c;
        }
        return $out;
    }

    /**
     * Uloží objednávku. Vrací řádek objednávky.
     * @param array<string,mixed> $event
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public function register(array $event, array $in, string $ip = '', string $channel = 'online', string $actor = 'verejnost', ?\DateTimeImmutable $now = null): array
    {
        $now ??= Util::now();
        $data = $this->validate($event, $in, $channel);
        $eventId = (int) $event['id'];

        $orderId = $this->db->transaction(function () use ($event, $eventId, $data, $ip, $channel, $actor, $now): int {
            // Zámek akce – všechny registrace na akci se zpracují jedna po druhé.
            $locked = $this->db->one('SELECT * FROM events WHERE id = ? FOR UPDATE', [$eventId]);
            if (!$locked) {
                throw new ValidationException(['event' => 'Akce neexistuje.']);
            }
            $event = array_merge($event, $locked);
            $av = $this->events->availability($event, $now, $channel);
            if (!$av['open'] && $av['free'] !== 0) {
                throw new ValidationException(['event' => $av['reason']], $av['reason']);
            }
            // Kapacita akce a tratí
            $occ = $this->events->occupancy($eventId);
            $need = count($data['runners']);
            if ($event['capacity'] !== null && $occ['event'] + $need > (int) $event['capacity']) {
                $free = max(0, (int) $event['capacity'] - $occ['event']);
                $msg = $free === 0 ? 'Kapacita závodu je naplněna.' : "Volných míst zbývá jen $free.";
                throw new ValidationException(['event' => $msg], $msg);
            }
            $perRace = [];
            foreach ($data['runners'] as $r) {
                $perRace[$r['race_id']] = ($perRace[$r['race_id']] ?? 0) + 1;
            }
            foreach ($event['races'] as $race) {
                $rid = (int) $race['id'];
                if ($race['capacity'] !== null && isset($perRace[$rid]) && ($occ['races'][$rid] ?? 0) + $perRace[$rid] > (int) $race['capacity']) {
                    $msg = 'Trať ' . Util::tr($race['name']) . ' je obsazená.';
                    throw new ValidationException(['event' => $msg], $msg);
                }
            }
            // Duplicitní přihlášení (např. dvojí odeslání)
            foreach ($data['runners'] as $i => $r) {
                $dup = $this->db->value(
                    "SELECT COUNT(*) FROM registrations WHERE event_id = ? AND first_name = ? AND last_name = ? AND birth_date = ? AND status IN ('ceka_na_platbu','potvrzena','ke_kontrole')",
                    [$eventId, $r['first_name'], $r['last_name'], $r['birth_date']]
                );
                if ((int) $dup > 0) {
                    throw new ValidationException(["runners.$i.last_name" => $r['first_name'] . ' ' . $r['last_name'] . ' už je na tento závod přihlášen(a).']);
                }
            }
            $coupons = $this->loadCoupons($eventId, array_column($data['runners'], 'coupon'), true);
            $p = $this->price($event, $data['runners'], $coupons, $now, $channel);
            if ($p['errors']) {
                throw new ValidationException($p['errors']);
            }
            $total = array_sum(array_column($p['lines'], 'final')) + $data['charity'];

            // Variabilní symbol
            $seq = (int) $locked['vs_next'];
            $vs = Vs::make((int) $event['year'], (int) $event['vs_code'], $seq);
            $this->db->run('UPDATE events SET vs_next = vs_next + 1 WHERE id = ?', [$eventId]);

            $bank = $event['bank'];
            $first = $data['runners'][0];
            $paid = $total === 0;
            $orderId = $this->db->insert('orders', [
                'event_id' => $eventId,
                'vs' => $vs,
                'public_token' => Util::token(32),
                'contact_name' => $first['first_name'] . ' ' . $first['last_name'],
                'contact_email' => $first['email'],
                'contact_phone' => $first['phone'],
                'language' => 'cs',
                'currency' => $event['currency'],
                'channel' => $channel,
                'bank_account_id' => (int) $bank['id'],
                'pay_account' => $bank['account_number'] . '/' . $bank['bank_code'],
                'pay_iban' => $bank['iban'],
                'amount_entry' => $p['entry'],
                'amount_products' => $p['products'],
                'amount_discount' => $p['discount'],
                'amount_charity' => $data['charity'],
                'amount_total' => $total,
                'amount_paid' => 0,
                'due_date' => $paid ? null : $now->modify('+' . (int) $event['payment_due_days'] . ' days')->format('Y-m-d'),
                'payment_status' => $paid ? 'zaplaceno' : 'nezaplaceno',
                'paid_at' => $paid ? Util::sqlDt($now) : null,
                'note' => $data['note'] ?: null,
                'ip' => $ip ?: null,
                'created_at' => Util::sqlDt($now),
            ]);
            foreach ($data['runners'] as $i => $r) {
                $q = $p['lines'][$i];
                $regId = $this->db->insert('registrations', [
                    'order_id' => $orderId,
                    'event_id' => $eventId,
                    'race_id' => $r['race_id'],
                    'first_name' => $r['first_name'],
                    'last_name' => $r['last_name'],
                    'birth_date' => $r['birth_date'],
                    'gender' => $r['gender'],
                    'email' => $r['email'],
                    'phone' => $r['phone'] ?: null,
                    'club' => $r['club'] ?: null,
                    'country' => $r['country'],
                    'guardian_name' => $r['minor'] ? $r['guardian_name'] : null,
                    'price_tier_id' => $q['tier'] ? (int) $q['tier']['id'] : null,
                    'price_base' => $q['base'],
                    'age_discount' => $q['age_discount'],
                    'coupon_id' => $q['coupon'] ? (int) $q['coupon']['id'] : null,
                    'coupon_discount' => $q['coupon_discount'],
                    'price_products' => $q['products'],
                    'price_final' => $q['final'],
                    'category_id' => $this->category($eventId, $r),
                    'status' => $paid ? 'potvrzena' : 'ceka_na_platbu',
                    'checkin_code' => Util::code(10),
                    'created_at' => Util::sqlDt($now),
                ]);
                foreach ($r['fields'] as $v) {
                    $this->db->insert('registration_field_values', [
                        'registration_id' => $regId, 'field_id' => $v['field_id'], 'option_id' => $v['option_id'],
                        'value_text' => $v['value_text'], 'surcharge' => $v['surcharge'],
                    ]);
                }
                $types = [];
                if ($data['consents']['podminky']) {
                    $types[] = 'podminky';
                }
                if ($data['consents']['osobni_udaje']) {
                    $types[] = 'osobni_udaje';
                    $types[] = 'verejna_startovka';
                }
                if ($r['minor'] && $r['guardian_consent']) {
                    $types[] = 'souhlas_rodice';
                }
                foreach ($types as $t) {
                    $this->db->insert('consents', [
                        'registration_id' => $regId, 'type' => $t, 'text_version' => self::CONSENT_VERSION,
                        'accepted_at' => Util::sqlDt($now), 'ip' => $ip ?: null,
                    ]);
                }
                if ($q['coupon']) {
                    $this->db->run('UPDATE coupons SET used_count = used_count + 1 WHERE id = ?', [(int) $q['coupon']['id']]);
                }
            }
            $this->log->audit('order', $orderId, 'vytvoreni', null, ['vs' => $vs, 'total' => $total, 'runners' => count($data['runners']), 'channel' => $channel], $actor);
            return $orderId;
        });

        if ($this->afterCreate) {
            try {
                ($this->afterCreate)($orderId);
            } catch (\Throwable $e) {
                $this->log->system('chyba', 'email', 'Po registraci se nepodařilo zařadit e-mail: ' . $e->getMessage(), ['order_id' => $orderId]);
            }
        }
        return (array) $this->db->one('SELECT * FROM orders WHERE id = ?', [$orderId]);
    }

    /** Automatické přiřazení kategorie podle tratě, pohlaví a věku (ročníku). @param array<string,mixed> $r */
    private function category(int $eventId, array $r): ?int
    {
        $year = (int) $this->db->value('SELECT YEAR(starts_at) FROM events WHERE id = ?', [$eventId]);
        $age = Pricing::ageByYear($r['birth_date'], $year);
        $cats = $this->db->all('SELECT * FROM categories WHERE event_id = ? ORDER BY sort_order, id', [$eventId]);
        foreach ($cats as $c) {
            if ($c['race_id'] !== null && (int) $c['race_id'] !== (int) $r['race_id']) {
                continue;
            }
            if ($c['gender'] !== null && $c['gender'] !== $r['gender']) {
                continue;
            }
            if ($c['age_from'] !== null && $age < (int) $c['age_from']) {
                continue;
            }
            if ($c['age_to'] !== null && $age > (int) $c['age_to']) {
                continue;
            }
            return (int) $c['id'];
        }
        return null;
    }
}
