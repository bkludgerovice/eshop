<?php
declare(strict_types=1);

namespace Bkl\Admin;

use Bkl\Http\Request;
use Bkl\Http\Response;
use Bkl\Util;

/** Správa akcí: základní nastavení, tratě, ceny, slevy, kupony, vlastní pole, charita, kategorie. */
final class EventAdmin
{
    public function __construct(private AdminController $c)
    {
    }

    private function db(): \Bkl\Db
    {
        return $this->c->app->db;
    }

    public function index(Request $req): Response
    {
        $events = $this->db()->all(
            "SELECT e.*, (SELECT COUNT(*) FROM registrations r WHERE r.event_id = e.id AND r.status IN ('ceka_na_platbu','potvrzena','ke_kontrole')) n
             FROM events e ORDER BY e.starts_at DESC"
        );
        return $this->c->page('admin/events', ['events' => $events, 'title' => 'Akce']);
    }

    public function create(Request $req): Response
    {
        if ($req->isPost() && $this->c->can('edit')) {
            $name = Util::clean($req->p('name'), 200);
            $slug = self::slug($req->p('slug') ?: $name);
            $start = self::dt($req->p('starts_at'));
            if ($name === '' || !$start || $slug === '') {
                $this->c->flash('Vyplňte název a datum konání.', 'err');
                return $this->c->back('akce/nova');
            }
            if ($this->db()->value('SELECT id FROM events WHERE slug = ?', [$slug])) {
                $slug .= '-' . substr($start, 0, 4);
            }
            $year = substr($start, 0, 4);
            $used = array_map('intval', array_column($this->db()->all('SELECT vs_code FROM events WHERE YEAR(starts_at) = ?', [$year]), 'vs_code'));
            $code = 1;
            while (in_array($code, $used, true) && $code < 99) {
                $code++;
            }
            $id = $this->db()->insert('events', [
                'slug' => $slug, 'vs_code' => $code, 'name' => Util::json(['cs' => $name]), 'starts_at' => $start,
                'status' => 'pripravovana', 'contact_email' => 'registrace@bkludgerovice.cz', 'web_url' => 'https://www.bkludgerovice.cz',
            ]);
            $this->c->app->log->audit('event', $id, 'zalozeni', null, ['slug' => $slug], $this->c->actor());
            $this->c->flash('Akce založena. Doplňte tratě, ceny a další nastavení. Akce je ve stavu „připravovaná“ – registrace se otevřou až změnou stavu.');
            return $this->c->back('akce/' . $id);
        }
        return $this->c->page('admin/event_new', ['title' => 'Nová akce']);
    }

    public function edit(Request $req, int $id): Response
    {
        $e = $this->c->app->events->byId($id);
        if (!$e) {
            return $this->c->page('admin/message', ['title' => 'Nenalezeno', 'text' => 'Akce neexistuje.'], 404);
        }
        if ($req->isPost()) {
            return $this->saveSettings($req, $e);
        }
        $db = $this->db();
        return $this->c->page('admin/event_edit', [
            'ev' => $e,
            'allRaces' => $db->all('SELECT * FROM races WHERE event_id = ? ORDER BY sort_order, id', [$id]),
            'allFields' => $db->all('SELECT * FROM form_fields WHERE event_id = ? ORDER BY sort_order, id', [$id]),
            'allOptions' => $db->all('SELECT o.* FROM form_field_options o JOIN form_fields f ON f.id = o.field_id WHERE f.event_id = ? ORDER BY o.sort_order, o.id', [$id]),
            'coupons' => $db->all("SELECT * FROM coupons WHERE event_id = ? ORDER BY kind = 'jednorazovy', id DESC LIMIT 500", [$id]),
            'couponUsers' => $db->all(
                "SELECT c.code, r.first_name, r.last_name, o.vs, o.id order_id FROM registrations r JOIN coupons c ON c.id = r.coupon_id JOIN orders o ON o.id = r.order_id
                 WHERE r.event_id = ? ORDER BY r.id DESC LIMIT 300",
                [$id]
            ),
            'charityAll' => $db->one('SELECT * FROM charity_campaigns WHERE event_id = ? ORDER BY id LIMIT 1', [$id]),
            'categories' => $db->all('SELECT * FROM categories WHERE event_id = ? ORDER BY sort_order, id', [$id]),
            'banks' => $db->all('SELECT * FROM bank_accounts WHERE active = 1'),
            'regCount' => (int) $db->value('SELECT COUNT(*) FROM registrations WHERE event_id = ?', [$id]),
            'title' => Util::tr($e['name']),
        ]);
    }

    /** @param array<string,mixed> $e */
    private function saveSettings(Request $req, array $e): Response
    {
        $id = (int) $e['id'];
        $p = $req->post;
        $slug = self::slug((string) ($p['slug'] ?? $e['slug']));
        if ($slug !== $e['slug'] && $this->db()->value('SELECT id FROM events WHERE slug = ? AND id <> ?', [$slug, $id])) {
            $this->c->flash('Adresa (slug) už je použitá u jiné akce.', 'err');
            return $this->c->back('akce/' . $id);
        }
        $vsCode = max(1, min(99, (int) ($p['vs_code'] ?? $e['vs_code'])));
        $hasOrders = (int) $this->db()->value('SELECT COUNT(*) FROM orders WHERE event_id = ?', [$id]) > 0;
        if ($hasOrders) {
            $vsCode = (int) $e['vs_code']; // po první objednávce se číslo akce ve VS nemění
        }
        $start = self::dt((string) ($p['starts_at'] ?? '')) ?? $e['starts_at'];
        $row = [
            'slug' => $slug,
            'vs_code' => $vsCode,
            'name' => Util::json(['cs' => Util::clean($p['name'] ?? '', 200)] + (array) json_decode((string) $e['name'], true)),
            'description' => Util::json(['cs' => mb_substr(trim((string) ($p['description'] ?? '')), 0, 5000)]),
            'starts_at' => $start,
            'venue' => Util::clean($p['venue'] ?? '', 255) ?: null,
            'logo_url' => self::url($p['logo_url'] ?? ''),
            'image_url' => self::url($p['image_url'] ?? ''),
            'contact_email' => Util::isEmail((string) ($p['contact_email'] ?? '')) ? $p['contact_email'] : null,
            'contact_phone' => Util::clean($p['contact_phone'] ?? '', 40) ?: null,
            'web_url' => self::url($p['web_url'] ?? ''),
            'terms_url' => self::url($p['terms_url'] ?? ''),
            'gdpr_url' => self::url($p['gdpr_url'] ?? ''),
            'reg_opens_at' => self::dt((string) ($p['reg_opens_at'] ?? '')),
            'reg_closes_at' => self::dt((string) ($p['reg_closes_at'] ?? '')),
            'status' => in_array($p['status'] ?? '', ['pripravovana', 'otevreno', 'pozastaveno', 'uzavreno', 'ukoncena', 'archivovana'], true) ? $p['status'] : $e['status'],
            'capacity' => ($p['capacity'] ?? '') === '' ? null : max(0, (int) $p['capacity']),
            'bank_account_id' => (int) ($p['bank_account_id'] ?? $e['bank_account_id']),
            'payment_due_days' => max(1, min(60, (int) ($p['payment_due_days'] ?? 5))),
            'reminder_days_before_due' => max(0, min(30, (int) ($p['reminder_days_before_due'] ?? 1))),
            'overdue_action' => ($p['overdue_action'] ?? '') === 'zrusit' ? 'zrusit' : 'rucne',
            'overdue_grace_days' => max(0, min(30, (int) ($p['overdue_grace_days'] ?? 2))),
            'transfer_deadline' => self::dt((string) ($p['transfer_deadline'] ?? '')),
            'onsite_enabled' => empty($p['onsite_enabled']) ? 0 : 1,
            'discount_applies_to_products' => empty($p['discount_applies_to_products']) ? 0 : 1,
            'max_runners_per_order' => max(1, min(30, (int) ($p['max_runners_per_order'] ?? 10))),
            'guardian_age' => max(0, min(21, (int) ($p['guardian_age'] ?? 18))),
            'color_primary' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($p['color_primary'] ?? '')) ? $p['color_primary'] : '#db131e',
            'embed_origins' => Util::clean($p['embed_origins'] ?? '', 1000),
            'sender_name' => Util::clean($p['sender_name'] ?? '', 150) ?: null,
            'reply_to' => Util::isEmail((string) ($p['reply_to'] ?? '')) ? $p['reply_to'] : null,
            'public_list_enabled' => empty($p['public_list_enabled']) ? 0 : 1,
        ];
        if (($p['checkin_pin'] ?? '') !== '') {
            $row['checkin_pin_hash'] = password_hash((string) $p['checkin_pin'], PASSWORD_DEFAULT);
        }
        $old = array_intersect_key($e, $row);
        $this->db()->update('events', $row, ['id' => $id]);
        $changed = array_diff_assoc(array_map('strval', $row), array_map(static fn ($v) => (string) $v, $old));
        unset($changed['checkin_pin_hash']);
        $this->c->app->log->audit('event', $id, 'nastaveni', array_intersect_key($old, $changed), $changed, $this->c->actor());
        $this->c->flash('Nastavení uloženo.');
        return $this->c->back('akce/' . $id);
    }

    /** Přidání / úprava / smazání podřízených položek. */
    public function sub(Request $req, int $id, string $what): Response
    {
        if (!$req->isPost() || !$this->c->can('edit')) {
            return $this->c->back('akce/' . $id);
        }
        $p = $req->post;
        $act = (string) ($p['action'] ?? 'save');
        $itemId = (int) ($p['id'] ?? 0);
        $db = $this->db();
        $anchor = '#' . $what;
        try {
            switch ($what) {
                case 'trat':
                    $row = [
                        'event_id' => $id,
                        'name' => Util::json(['cs' => Util::clean($p['name'] ?? '', 100)]),
                        'distance_km' => ($p['distance_km'] ?? '') === '' ? null : (float) str_replace(',', '.', (string) $p['distance_km']),
                        'capacity' => ($p['capacity'] ?? '') === '' ? null : (int) $p['capacity'],
                        'reg_opens_at' => self::dt((string) ($p['reg_opens_at'] ?? '')),
                        'reg_closes_at' => self::dt((string) ($p['reg_closes_at'] ?? '')),
                        'min_age' => ($p['min_age'] ?? '') === '' ? null : (int) $p['min_age'],
                        'max_age' => ($p['max_age'] ?? '') === '' ? null : (int) $p['max_age'],
                        'bib_from' => ($p['bib_from'] ?? '') === '' ? null : (int) $p['bib_from'],
                        'bib_to' => ($p['bib_to'] ?? '') === '' ? null : (int) $p['bib_to'],
                        'sort_order' => (int) ($p['sort_order'] ?? 0),
                        'active' => $act === 'deactivate' ? 0 : 1,
                    ];
                    if ($act === 'deactivate' || $act === 'activate') {
                        $db->update('races', ['active' => $act === 'activate' ? 1 : 0], ['id' => $itemId, 'event_id' => $id]);
                    } elseif (Util::clean($p['name'] ?? '', 100) === '') {
                        throw new \InvalidArgumentException('Vyplňte název tratě.');
                    } elseif ($itemId) {
                        unset($row['event_id']);
                        $db->update('races', $row, ['id' => $itemId, 'event_id' => $id]);
                    } else {
                        $db->insert('races', $row);
                    }
                    break;

                case 'cena':
                    if ($act === 'delete') {
                        $db->run('DELETE FROM price_tiers WHERE id = ? AND event_id = ?', [$itemId, $id]);
                        break;
                    }
                    $amount = Util::parseMoney((string) ($p['amount'] ?? ''));
                    if ($amount === null || $amount < 0) {
                        throw new \InvalidArgumentException('Zadejte cenu v Kč.');
                    }
                    $row = [
                        'event_id' => $id,
                        'race_id' => ($p['race_id'] ?? '') === '' ? null : (int) $p['race_id'],
                        'name' => Util::clean($p['name'] ?? '', 100),
                        'valid_from' => self::dt((string) ($p['valid_from'] ?? '')),
                        'valid_to' => self::dt((string) ($p['valid_to'] ?? ''), true),
                        'channel' => ($p['channel'] ?? '') === 'na_miste' ? 'na_miste' : 'online',
                        'amount' => $amount,
                    ];
                    $itemId ? $db->update('price_tiers', $row, ['id' => $itemId, 'event_id' => $id]) : $db->insert('price_tiers', $row);
                    break;

                case 'vek':
                    if ($act === 'delete') {
                        $db->run('DELETE FROM age_discounts WHERE id = ? AND event_id = ?', [$itemId, $id]);
                        break;
                    }
                    $row = [
                        'event_id' => $id,
                        'name' => Util::clean($p['name'] ?? '', 100) ?: 'Věková sleva',
                        'age_max' => ($p['age_max'] ?? '') === '' ? null : (int) $p['age_max'],
                        'age_min' => ($p['age_min'] ?? '') === '' ? null : (int) $p['age_min'],
                        'percent' => max(0, min(100, (int) ($p['percent'] ?? 0))),
                    ];
                    if ($row['age_max'] === null && $row['age_min'] === null) {
                        throw new \InvalidArgumentException('Zadejte věk „do“ nebo „od“.');
                    }
                    $itemId ? $db->update('age_discounts', $row, ['id' => $itemId, 'event_id' => $id]) : $db->insert('age_discounts', $row);
                    break;

                case 'kupon':
                    if ($act === 'toggle') {
                        $db->run('UPDATE coupons SET active = 1 - active WHERE id = ? AND event_id = ?', [$itemId, $id]);
                        break;
                    }
                    $code = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', (string) ($p['code'] ?? '')) ?? '');
                    if (strlen($code) < 4) {
                        throw new \InvalidArgumentException('Kód musí mít alespoň 4 znaky (písmena, číslice).');
                    }
                    $pct = ($p['percent'] ?? '') === '' ? null : max(0, min(100, (int) $p['percent']));
                    $fixed = ($p['fixed'] ?? '') === '' ? null : Util::parseMoney((string) $p['fixed']);
                    if ($pct === null && $fixed === null) {
                        throw new \InvalidArgumentException('Zadejte slevu v % nebo v Kč.');
                    }
                    $kind = in_array($p['kind'] ?? '', ['clensky_spolecny', 'jednorazovy', 'obecny'], true) ? $p['kind'] : 'obecny';
                    $row = [
                        'event_id' => $id, 'code' => $code, 'kind' => $kind, 'label' => Util::clean($p['label'] ?? '', 150),
                        'percent' => $pct, 'fixed_amount' => $pct === null ? $fixed : null,
                        'max_uses' => $kind === 'jednorazovy' ? 1 : (($p['max_uses'] ?? '') === '' ? null : max(1, (int) $p['max_uses'])),
                        'valid_from' => self::dt((string) ($p['valid_from'] ?? '')),
                        'valid_to' => self::dt((string) ($p['valid_to'] ?? ''), true),
                    ];
                    if ($itemId) {
                        unset($row['event_id']);
                        $db->update('coupons', $row, ['id' => $itemId, 'event_id' => $id]);
                    } else {
                        $db->insert('coupons', $row);
                    }
                    break;

                case 'kupony':
                    $n = max(1, min(500, (int) ($p['count'] ?? 0)));
                    $pct = max(1, min(100, (int) ($p['percent'] ?? 100)));
                    $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($p['prefix'] ?? 'P')) ?? 'P');
                    $batch = Util::clean($p['label'] ?? '', 60) ?: 'Dávka ' . date('j. n. Y H:i');
                    $made = [];
                    for ($i = 0; $i < $n; $i++) {
                        $code = $prefix . '-' . Util::code(6);
                        try {
                            $db->insert('coupons', ['event_id' => $id, 'code' => $code, 'kind' => 'jednorazovy', 'label' => $batch, 'percent' => $pct, 'max_uses' => 1, 'batch' => $batch]);
                            $made[] = $code;
                        } catch (\PDOException) {
                            $i--; // kolize kódu – zkusit znovu
                        }
                    }
                    $this->c->app->log->audit('event', $id, 'kupony_generovani', null, ['count' => $n, 'percent' => $pct, 'batch' => $batch], $this->c->actor());
                    $this->c->flash('Vygenerováno ' . count($made) . ' kódů: ' . implode(', ', $made));
                    return $this->c->back('akce/' . $id . $anchor);

                case 'pole':
                    if ($act === 'toggle') {
                        $db->run('UPDATE form_fields SET active = 1 - active WHERE id = ? AND event_id = ?', [$itemId, $id]);
                        break;
                    }
                    $label = Util::clean($p['label'] ?? '', 200);
                    if ($label === '') {
                        throw new \InvalidArgumentException('Vyplňte název pole.');
                    }
                    $type = in_array($p['type'] ?? '', ['text', 'number', 'date', 'select', 'multiselect', 'checkbox'], true) ? $p['type'] : 'text';
                    $row = [
                        'event_id' => $id, 'type' => $type,
                        'race_id' => ($p['race_id'] ?? '') === '' ? null : (int) $p['race_id'],
                        'label' => Util::json(['cs' => $label]),
                        'help' => Util::clean($p['help'] ?? '', 300) !== '' ? Util::json(['cs' => Util::clean($p['help'], 300)]) : null,
                        'required' => empty($p['required']) ? 0 : 1,
                        'is_product' => empty($p['is_product']) ? 0 : 1,
                        'sort_order' => (int) ($p['sort_order'] ?? 0),
                    ];
                    if ($itemId) {
                        unset($row['event_id']);
                        $db->update('form_fields', $row, ['id' => $itemId, 'event_id' => $id]);
                    } else {
                        $row['field_key'] = substr(self::slug($label), 0, 40) . '-' . strtolower(Util::code(4));
                        $db->insert('form_fields', $row);
                    }
                    break;

                case 'moznost':
                    $field = $db->one('SELECT * FROM form_fields WHERE id = ? AND event_id = ?', [(int) ($p['field_id'] ?? 0), $id]);
                    if (!$field) {
                        throw new \InvalidArgumentException('Pole neexistuje.');
                    }
                    if ($act === 'toggle') {
                        $db->run('UPDATE form_field_options SET active = 1 - active WHERE id = ? AND field_id = ?', [$itemId, (int) $field['id']]);
                        break;
                    }
                    $label = Util::clean($p['label'] ?? '', 200);
                    $sur = ($p['surcharge'] ?? '') === '' ? 0 : Util::parseMoney((string) $p['surcharge']);
                    if ($label === '' || $sur === null) {
                        throw new \InvalidArgumentException('Vyplňte text možnosti a případný příplatek v Kč.');
                    }
                    $row = ['field_id' => (int) $field['id'], 'label' => Util::json(['cs' => $label]), 'surcharge' => $sur, 'sort_order' => (int) ($p['sort_order'] ?? 0)];
                    $itemId ? $db->update('form_field_options', $row, ['id' => $itemId, 'field_id' => (int) $field['id']]) : $db->insert('form_field_options', $row);
                    break;

                case 'charita':
                    $row = [
                        'event_id' => $id,
                        'purpose' => Util::json(['cs' => Util::clean($p['purpose'] ?? '', 300)]),
                        'recipient' => Util::clean($p['recipient'] ?? '', 200),
                        'preset_amounts' => implode(',', array_filter(array_map('intval', preg_split('/[\s,;]+/', (string) ($p['preset_amounts'] ?? '')) ?: []))),
                        'allow_custom' => empty($p['allow_custom']) ? 0 : 1,
                        'active' => empty($p['active']) ? 0 : 1,
                    ];
                    $ex = $db->value('SELECT id FROM charity_campaigns WHERE event_id = ? ORDER BY id LIMIT 1', [$id]);
                    $ex ? $db->update('charity_campaigns', $row, ['id' => (int) $ex]) : $db->insert('charity_campaigns', $row);
                    break;

                case 'kategorie':
                    if ($act === 'delete') {
                        $db->run('DELETE FROM categories WHERE id = ? AND event_id = ?', [$itemId, $id]);
                        break;
                    }
                    $row = [
                        'event_id' => $id,
                        'race_id' => ($p['race_id'] ?? '') === '' ? null : (int) $p['race_id'],
                        'code' => Util::clean($p['code'] ?? '', 20),
                        'name' => Util::clean($p['name'] ?? '', 150),
                        'gender' => in_array($p['gender'] ?? '', ['M', 'Z'], true) ? $p['gender'] : null,
                        'age_from' => ($p['age_from'] ?? '') === '' ? null : (int) $p['age_from'],
                        'age_to' => ($p['age_to'] ?? '') === '' ? null : (int) $p['age_to'],
                        'sort_order' => (int) ($p['sort_order'] ?? 0),
                    ];
                    if ($row['code'] === '' || $row['name'] === '') {
                        throw new \InvalidArgumentException('Vyplňte zkratku a název kategorie.');
                    }
                    $itemId ? $db->update('categories', $row, ['id' => $itemId, 'event_id' => $id]) : $db->insert('categories', $row);
                    break;
            }
            $this->c->app->log->audit('event', $id, $what . '_' . $act, null, array_diff_key($p, ['csrf' => 1]), $this->c->actor());
            $this->c->flash('Uloženo.');
        } catch (\InvalidArgumentException $ex) {
            $this->c->flash($ex->getMessage(), 'err');
        } catch (\PDOException $ex) {
            $this->c->flash(str_contains($ex->getMessage(), 'Duplicate') ? 'Tato hodnota (např. kód kuponu) už existuje.' : 'Uložení se nepodařilo: ' . $ex->getMessage(), 'err');
        }
        return $this->c->back('akce/' . $id . $anchor);
    }

    public static function slug(string $s): string
    {
        $s = Util::fold($s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        return substr(trim($s, '-'), 0, 100);
    }

    /** Hodnota z <input type="datetime-local"> nebo „d. m. Y H:i“ → SQL datetime; prázdné → null. */
    public static function dt(string $v, bool $endOfDay = false): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        foreach (['Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i', 'j. n. Y H:i', 'j.n.Y H:i'] as $f) {
            $d = \DateTimeImmutable::createFromFormat($f, $v, Util::tz());
            if ($d) {
                return $d->format('Y-m-d H:i:' . ($endOfDay ? '59' : '00'));
            }
        }
        foreach (['Y-m-d', 'j. n. Y', 'j.n.Y'] as $f) {
            $d = \DateTimeImmutable::createFromFormat('!' . $f, $v, Util::tz());
            if ($d) {
                return $d->format('Y-m-d') . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
            }
        }
        return null;
    }

    private static function url(mixed $v): ?string
    {
        $v = trim((string) $v);
        return preg_match('#^https?://\S+$#i', $v) ? mb_substr($v, 0, 500) : null;
    }
}
