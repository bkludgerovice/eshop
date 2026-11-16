<?php
declare(strict_types=1);

namespace Bkl\Admin;

use Bkl\Domain\Pricing;
use Bkl\Domain\RegistrationService;
use Bkl\Domain\Vs;
use Bkl\Http\Request;
use Bkl\Http\Response;
use Bkl\Util;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/** Registrace, detail objednávky, platby, export. */
final class RegAdmin
{
    public const STATUSES = ['ceka_na_platbu' => 'čeká na platbu', 'potvrzena' => 'potvrzená', 'ke_kontrole' => 'ke kontrole', 'zrusena' => 'zrušená', 'expirovana' => 'expirovaná', 'rozpracovana' => 'rozpracovaná'];
    public const PAY = ['nezaplaceno' => 'nezaplaceno', 'castecne' => 'částečně', 'zaplaceno' => 'zaplaceno', 'preplaceno' => 'přeplaceno', 'vraceno' => 'vráceno', 'ke_kontrole' => 'ke kontrole'];

    public function __construct(private AdminController $c)
    {
    }

    private function db(): \Bkl\Db
    {
        return $this->c->app->db;
    }

    // ---------- seznam ----------

    public function list(Request $req, int $eventId): Response
    {
        $e = $this->c->app->events->byId($eventId);
        if (!$e) {
            return $this->c->page('admin/message', ['title' => 'Nenalezeno', 'text' => 'Akce neexistuje.'], 404);
        }
        [$where, $params] = $this->filters($req, $eventId);
        $rows = $this->db()->all(
            "SELECT r.*, o.vs, o.payment_status, o.id order_id, o.amount_total, o.channel, ra.name race_name, c.code coupon_code
             FROM registrations r JOIN orders o ON o.id = r.order_id JOIN races ra ON ra.id = r.race_id LEFT JOIN coupons c ON c.id = r.coupon_id
             WHERE $where ORDER BY r.id DESC LIMIT 2000",
            $params
        );
        return $this->c->page('admin/regs', ['ev' => $e, 'rows' => $rows, 'f' => $req->query, 'title' => 'Registrace – ' . Util::tr($e['name'])]);
    }

    /** @return array{0:string,1:list<mixed>} */
    private function filters(Request $req, int $eventId): array
    {
        $w = ['r.event_id = ?'];
        $p = [$eventId];
        if ($req->q('race') !== '') {
            $w[] = 'r.race_id = ?';
            $p[] = (int) $req->q('race');
        }
        $st = $req->q('status', 'aktivni');
        if ($st === 'aktivni') {
            $w[] = "r.status IN ('ceka_na_platbu','potvrzena','ke_kontrole')";
        } elseif (isset(self::STATUSES[$st])) {
            $w[] = 'r.status = ?';
            $p[] = $st;
        }
        if (isset(self::PAY[$req->q('pay')])) {
            $w[] = 'o.payment_status = ?';
            $p[] = $req->q('pay');
        }
        $q = trim($req->q('q'));
        if ($q !== '') {
            $w[] = '(r.last_name LIKE ? OR r.first_name LIKE ? OR r.email LIKE ? OR r.club LIKE ? OR o.vs LIKE ? OR CONCAT(r.first_name, " ", r.last_name) LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            array_push($p, $like, $like, $like, $like, $like, $like);
        }
        return [implode(' AND ', $w), $p];
    }

    // ---------- detail objednávky ----------

    public function order(Request $req, int $orderId): Response
    {
        $db = $this->db();
        $o = $db->one('SELECT * FROM orders WHERE id = ?', [$orderId]);
        if (!$o) {
            return $this->c->page('admin/message', ['title' => 'Nenalezeno', 'text' => 'Objednávka neexistuje.'], 404);
        }
        if ($req->isPost()) {
            try {
                $this->orderAction($req, $o);
            } catch (\InvalidArgumentException $ex) {
                $this->c->flash($ex->getMessage(), 'err');
            }
            return $this->c->back('objednavka/' . $orderId);
        }
        $e = $this->c->app->events->byId((int) $o['event_id']);
        $regs = $db->all('SELECT r.*, ra.name race_name, c.code coupon_code, cat.code cat_code FROM registrations r JOIN races ra ON ra.id = r.race_id LEFT JOIN coupons c ON c.id = r.coupon_id LEFT JOIN categories cat ON cat.id = r.category_id WHERE r.order_id = ? ORDER BY r.id', [$orderId]);
        $values = [];
        foreach ($db->all('SELECT v.*, f.label FROM registration_field_values v JOIN form_fields f ON f.id = v.field_id JOIN registrations r ON r.id = v.registration_id WHERE r.order_id = ?', [$orderId]) as $v) {
            $values[(int) $v['registration_id']][] = $v;
        }
        $consents = [];
        foreach ($db->all('SELECT c.* FROM consents c JOIN registrations r ON r.id = c.registration_id WHERE r.order_id = ?', [$orderId]) as $cs) {
            $consents[(int) $cs['registration_id']][] = $cs;
        }
        $regIds = array_map(static fn ($r) => (int) $r['id'], $regs) ?: [0];
        $audit = $db->all(
            "SELECT * FROM audit_log WHERE (entity = 'order' AND entity_id = ?) OR (entity = 'registration' AND entity_id IN (" . implode(',', $regIds) . ")) ORDER BY id DESC LIMIT 100",
            [$orderId]
        );
        return $this->c->page('admin/order', [
            'ev' => $e, 'o' => $o, 'regs' => $regs, 'values' => $values, 'consents' => $consents, 'audit' => $audit,
            'payments' => $db->all('SELECT * FROM payments WHERE order_id = ? ORDER BY received_at', [$orderId]),
            'refunds' => $db->all('SELECT * FROM refunds WHERE order_id = ? ORDER BY id', [$orderId]),
            'mails' => $db->all('SELECT id, to_email, subject, status, attempts, last_error, created_at, sent_at FROM email_queue WHERE order_id = ? ORDER BY id DESC', [$orderId]),
            'races' => $db->all('SELECT * FROM races WHERE event_id = ? ORDER BY sort_order', [(int) $o['event_id']]),
            'title' => 'Objednávka ' . $o['vs'],
        ]);
    }

    /** @param array<string,mixed> $o */
    private function orderAction(Request $req, array $o): void
    {
        $db = $this->db();
        $app = $this->c->app;
        $actor = $this->c->actor();
        $orderId = (int) $o['id'];
        $p = $req->post;
        $reg = null;
        if (!empty($p['reg_id'])) {
            $reg = $db->one('SELECT * FROM registrations WHERE id = ? AND order_id = ?', [(int) $p['reg_id'], $orderId]);
            if (!$reg) {
                throw new \InvalidArgumentException('Registrace nepatří k objednávce.');
            }
        }
        switch ((string) ($p['action'] ?? '')) {
            case 'reg_save':
                $bd = (string) ($p['birth_date'] ?? '');
                if (!\DateTimeImmutable::createFromFormat('!Y-m-d', $bd)) {
                    throw new \InvalidArgumentException('Neplatné datum narození (RRRR-MM-DD).');
                }
                $race = $db->one('SELECT * FROM races WHERE id = ? AND event_id = ?', [(int) $p['race_id'], (int) $o['event_id']]);
                if (!$race) {
                    throw new \InvalidArgumentException('Neplatná trať.');
                }
                $bib = trim((string) ($p['bib'] ?? '')) === '' ? null : (int) $p['bib'];
                if ($bib !== null && $db->value('SELECT id FROM registrations WHERE event_id = ? AND bib = ? AND id <> ?', [(int) $o['event_id'], $bib, (int) $reg['id']])) {
                    throw new \InvalidArgumentException("Startovní číslo $bib už má jiný závodník.");
                }
                $row = [
                    'first_name' => Util::clean($p['first_name'] ?? '', 100), 'last_name' => Util::clean($p['last_name'] ?? '', 100),
                    'birth_date' => $bd, 'gender' => ($p['gender'] ?? '') === 'Z' ? 'Z' : 'M',
                    'email' => mb_strtolower(Util::clean($p['email'] ?? '', 190)), 'phone' => Util::clean($p['phone'] ?? '', 40) ?: null,
                    'club' => Util::clean($p['club'] ?? '', 150) ?: null,
                    'country' => isset(RegistrationService::COUNTRIES[$p['country'] ?? '']) ? $p['country'] : $reg['country'],
                    'race_id' => (int) $race['id'], 'bib' => $bib,
                    'guardian_name' => Util::clean($p['guardian_name'] ?? '', 200) ?: null,
                ];
                if ($row['first_name'] === '' || $row['last_name'] === '' || !Util::isEmail($row['email'])) {
                    throw new \InvalidArgumentException('Jméno, příjmení a platný e-mail jsou povinné.');
                }
                if ((int) $race['id'] !== (int) $reg['race_id'] && $race['capacity'] !== null) {
                    $occ = $app->events->occupancy((int) $o['event_id']);
                    if (($occ['races'][(int) $race['id']] ?? 0) >= (int) $race['capacity']) {
                        $this->c->flash('Pozor: cílová trať je plná, změna byla přesto provedena (ruční zásah).', 'err');
                    }
                }
                $db->update('registrations', $row, ['id' => (int) $reg['id']]);
                $this->recategorize((int) $reg['id']);
                $app->log->audit('registration', (int) $reg['id'], 'uprava', array_intersect_key($reg, $row), $row, $actor);
                $this->c->flash('Údaje závodníka uloženy.');
                break;

            case 'reg_status':
                $to = (string) ($p['status'] ?? '');
                if (!in_array($to, ['ceka_na_platbu', 'potvrzena', 'ke_kontrole', 'zrusena'], true)) {
                    throw new \InvalidArgumentException('Neplatný stav.');
                }
                if (in_array($reg['status'], ['zrusena', 'expirovana'], true) && $to !== 'zrusena') {
                    $e = $app->events->byId((int) $o['event_id']);
                    $occ = $app->events->occupancy((int) $o['event_id']);
                    if ($e['capacity'] !== null && $occ['event'] >= (int) $e['capacity']) {
                        $this->c->flash('Pozor: kapacita akce je naplněná – obnovením ji překračujete.', 'err');
                    }
                }
                $app->payments->setStatus($reg, $to, $actor);
                $this->c->flash('Stav změněn.');
                break;

            case 'cancel_order':
                foreach ($db->all("SELECT * FROM registrations WHERE order_id = ? AND status NOT IN ('zrusena','expirovana')", [$orderId]) as $r) {
                    $app->payments->setStatus($r, 'zrusena', $actor);
                }
                if (!empty($p['notify'])) {
                    $app->notifier->cancelled($orderId);
                    $app->mailer->flush(10);
                }
                $this->c->flash('Objednávka zrušena. Startovné se nevrací automaticky – případné vrácení zadejte níže.');
                break;

            case 'payment_add':
                $amt = Util::parseMoney((string) ($p['amount'] ?? ''));
                if ($amt === null || $amt === 0) {
                    throw new \InvalidArgumentException('Zadejte částku v Kč.');
                }
                $app->payments->addManual($orderId, $amt, ($p['source'] ?? '') === 'hotovost' ? 'hotovost' : 'rucne', Util::clean($p['note'] ?? '', 300), $actor);
                $app->mailer->flush(10);
                $this->c->flash('Platba zaevidována.');
                break;

            case 'refund':
                $amt = Util::parseMoney((string) ($p['amount'] ?? ''));
                $reason = Util::clean($p['reason'] ?? '', 500);
                if ($amt === null || $amt <= 0 || $reason === '') {
                    throw new \InvalidArgumentException('Zadejte vracenou částku a důvod.');
                }
                $app->payments->refund($orderId, $amt, $reason, $actor);
                if (!empty($p['notify'])) {
                    $e = $app->events->byId((int) $o['event_id']);
                    $app->mailer->queue('vraceni-' . $orderId . '-' . time(), (string) $o['contact_email'], 'Vrácení platby: ' . Util::tr($e['name']),
                        \Bkl\Mail\Notifier::wrap($e, '<p>Dobrý den,</p><p>k vaší přihlášce (VS ' . Util::e($o['vs']) . ') jsme vrátili částku <b>' . Util::money($amt) . '</b>.</p><p>Důvod: ' . Util::e($reason) . '</p>'),
                        [], $orderId);
                    $app->mailer->flush(10);
                }
                $this->c->flash('Vrácení zaevidováno. Peníze odešlete z banky ručně.');
                break;

            case 'price':
                $new = Util::parseMoney((string) ($p['price_final'] ?? ''));
                if ($new === null || $new < 0) {
                    throw new \InvalidArgumentException('Zadejte cenu v Kč.');
                }
                $db->update('registrations', ['price_final' => $new], ['id' => (int) $reg['id']]);
                $this->retotal($orderId);
                $app->log->audit('registration', (int) $reg['id'], 'zmena_ceny', ['price_final' => $reg['price_final']], ['price_final' => $new, 'reason' => Util::clean($p['reason'] ?? '', 300)], $actor);
                $app->payments->recalculate($orderId, $actor);
                $this->c->flash('Cena upravena, součet objednávky přepočítán.');
                break;

            case 'resend':
                $app->notifier->resend($orderId, $actor);
                [$s] = $app->mailer->flush(10);
                $app->log->audit('order', $orderId, 'znovu_odeslat', null, null, $actor);
                $this->c->flash($s ? 'E-mail odeslán.' : 'E-mail zařazen do fronty.');
                break;

            case 'note':
                $db->update('orders', ['internal_note' => mb_substr((string) ($p['internal_note'] ?? ''), 0, 5000)], ['id' => $orderId]);
                $this->c->flash('Poznámka uložena.');
                break;

            case 'anonymize':
                if (($p['confirm'] ?? '') !== 'SMAZAT') {
                    throw new \InvalidArgumentException('Pro výmaz napište do pole slovo SMAZAT.');
                }
                $db->update('registrations', [
                    'first_name' => 'Vymazáno', 'last_name' => '#' . $reg['id'], 'email' => 'vymazano@invalid.invalid', 'phone' => null,
                    'club' => null, 'guardian_name' => null, 'birth_date' => substr((string) $reg['birth_date'], 0, 4) . '-01-01',
                    'anonymized_at' => Util::sqlDt(Util::now()),
                ], ['id' => (int) $reg['id']]);
                $db->run('DELETE FROM registration_field_values WHERE registration_id = ?', [(int) $reg['id']]);
                if ((int) $db->value("SELECT COUNT(*) FROM registrations WHERE order_id = ? AND anonymized_at IS NULL", [$orderId]) === 0) {
                    $db->update('orders', ['contact_name' => 'Vymazáno', 'contact_email' => 'vymazano@invalid.invalid', 'contact_phone' => null, 'note' => null, 'ip' => null], ['id' => $orderId]);
                }
                $app->log->audit('registration', (int) $reg['id'], 'vymaz_osobnich_udaju', null, ['na_zadost' => true], $actor);
                $this->c->flash('Osobní údaje závodníka byly vymazány (platby a účetní údaje zůstávají).');
                break;

            default:
                throw new \InvalidArgumentException('Neznámá akce.');
        }
    }

    private function retotal(int $orderId): void
    {
        $db = $this->db();
        $sum = (int) $db->value("SELECT COALESCE(SUM(price_final),0) FROM registrations WHERE order_id = ?", [$orderId]);
        $db->run('UPDATE orders SET amount_total = ? + amount_charity WHERE id = ?', [$sum, $orderId]);
    }

    private function recategorize(int $regId): void
    {
        $r = $this->db()->one('SELECT r.*, YEAR(e.starts_at) y FROM registrations r JOIN events e ON e.id = r.event_id WHERE r.id = ?', [$regId]);
        $age = Pricing::ageByYear((string) $r['birth_date'], (int) $r['y']);
        $cat = null;
        foreach ($this->db()->all('SELECT * FROM categories WHERE event_id = ? ORDER BY sort_order, id', [(int) $r['event_id']]) as $c) {
            if (($c['race_id'] === null || (int) $c['race_id'] === (int) $r['race_id']) && ($c['gender'] === null || $c['gender'] === $r['gender'])
                && ($c['age_from'] === null || $age >= (int) $c['age_from']) && ($c['age_to'] === null || $age <= (int) $c['age_to'])) {
                $cat = (int) $c['id'];
                break;
            }
        }
        $this->db()->update('registrations', ['category_id' => $cat], ['id' => $regId]);
    }

    // ---------- platby ----------

    public function payments(Request $req): Response
    {
        $db = $this->db();
        if ($req->isPost()) {
            $pid = (int) $req->p('payment_id');
            try {
                if ($req->p('action') === 'assign') {
                    $vs = Vs::normalize($req->p('vs'));
                    $o = $db->one('SELECT id FROM orders WHERE vs = ?', [$vs]);
                    if (!$o) {
                        throw new \InvalidArgumentException("Objednávka s VS $vs neexistuje.");
                    }
                    $this->c->app->payments->assign($pid, (int) $o['id'], $this->c->actor());
                    $this->c->app->mailer->flush(10);
                    $this->c->flash('Platba spárována s objednávkou ' . $vs . '.');
                } elseif ($req->p('action') === 'ignore') {
                    $this->c->app->payments->ignore($pid, $this->c->actor());
                    $this->c->flash('Platba označena jako nesouvisející.');
                }
            } catch (\InvalidArgumentException $ex) {
                $this->c->flash($ex->getMessage(), 'err');
            }
            return $this->c->back('platby');
        }
        return $this->c->page('admin/payments', [
            'unmatched' => $db->all("SELECT * FROM payments WHERE match_status = 'nesparovano' ORDER BY received_at DESC"),
            'recent' => $db->all('SELECT p.*, o.vs order_vs, o.contact_name FROM payments p LEFT JOIN orders o ON o.id = p.order_id ORDER BY p.id DESC LIMIT 200'),
            'title' => 'Platby',
        ]);
    }

    // ---------- export ----------

    /** @return array<string,string> */
    public static function columns(array $e): array
    {
        $cols = [
            'vs' => 'Variabilní symbol', 'bib' => 'Startovní číslo', 'last_name' => 'Příjmení', 'first_name' => 'Jméno',
            'birth_date' => 'Datum narození', 'rocnik' => 'Ročník', 'gender' => 'Pohlaví', 'category' => 'Kategorie',
            'race' => 'Trať', 'club' => 'Klub / město', 'country' => 'Země', 'email' => 'E-mail', 'phone' => 'Telefon',
            'status' => 'Stav registrace', 'payment_status' => 'Stav platby', 'price_base' => 'Startovné', 'discount' => 'Sleva',
            'coupon' => 'Kupon', 'products' => 'Produkty', 'price_final' => 'Cena celkem', 'charity' => 'Příspěvek (objednávka)',
            'paid' => 'Zaplaceno (objednávka)', 'guardian' => 'Zákonný zástupce', 'created_at' => 'Datum registrace',
            'note' => 'Poznámka', 'contact_email' => 'Kontaktní e-mail objednávky',
        ];
        foreach ($e['fields'] as $f) {
            $cols['f' . $f['id']] = Util::tr($f['label']);
        }
        return $cols;
    }

    public const PRESETS = [
        'casomira' => ['label' => 'Startovka pro časomíru', 'cols' => ['bib', 'last_name', 'first_name', 'rocnik', 'gender', 'club', 'race', 'category']],
        'kompletni' => ['label' => 'Kompletní přehled', 'cols' => ['vs', 'last_name', 'first_name', 'birth_date', 'gender', 'race', 'club', 'country', 'email', 'phone', 'status', 'payment_status', 'price_final', 'coupon', 'created_at']],
    ];

    public function export(Request $req, int $eventId): Response
    {
        $e = $this->c->app->events->byId($eventId);
        if (!$e) {
            return $this->c->page('admin/message', ['title' => 'Nenalezeno', 'text' => 'Akce neexistuje.'], 404);
        }
        $all = self::columns($e);
        $db = $this->db();
        if ($req->isPost()) {
            $cols = array_values(array_filter((array) ($req->post['cols'] ?? []), static fn ($c) => isset($all[$c])));
            if (!$cols) {
                $this->c->flash('Vyberte alespoň jeden sloupec.', 'err');
                return $this->c->back('akce/' . $eventId . '/export');
            }
            if ($req->p('save_as') !== '' && $this->c->can('edit')) {
                $db->insert('export_templates', ['event_id' => null, 'name' => Util::clean($req->p('save_as'), 150), 'columns' => Util::json($cols), 'filters' => Util::json(['race' => $req->p('race'), 'status' => $req->p('status')])]);
            }
            return $this->xlsx($e, $cols, $all, $req);
        }
        $templates = $db->all('SELECT * FROM export_templates ORDER BY name');
        return $this->c->page('admin/export', ['ev' => $e, 'all' => $all, 'templates' => $templates, 'presets' => self::PRESETS, 'title' => 'Export – ' . Util::tr($e['name'])]);
    }

    /** @param list<string> $cols @param array<string,string> $all @param array<string,mixed> $e */
    private function xlsx(array $e, array $cols, array $all, Request $req): Response
    {
        $q = new Request('GET', '/', ['race' => $req->p('race'), 'status' => $req->p('status', 'aktivni'), 'pay' => $req->p('pay'), 'q' => '']);
        [$where, $params] = $this->filters($q, (int) $e['id']);
        $sort = $req->p('sort') === 'bib' ? 'r.bib IS NULL, r.bib, r.last_name' : 'ra.sort_order, r.last_name, r.first_name';
        $rows = $this->db()->all(
            "SELECT r.*, o.vs, o.payment_status, o.amount_charity, o.amount_paid, o.note, o.contact_email, ra.name race_name, c.code coupon_code, cat.code cat_code
             FROM registrations r JOIN orders o ON o.id = r.order_id JOIN races ra ON ra.id = r.race_id
             LEFT JOIN coupons c ON c.id = r.coupon_id LEFT JOIN categories cat ON cat.id = r.category_id
             WHERE $where ORDER BY $sort",
            $params
        );
        $vals = [];
        if ($rows) {
            $ids = implode(',', array_map(static fn ($r) => (int) $r['id'], $rows));
            foreach ($this->db()->all("SELECT registration_id, field_id, value_text FROM registration_field_values WHERE registration_id IN ($ids)") as $v) {
                $k = (int) $v['registration_id'] . ':' . (int) $v['field_id'];
                $vals[$k] = isset($vals[$k]) ? $vals[$k] . ', ' . $v['value_text'] : $v['value_text'];
            }
        }
        $tmp = tempnam(sys_get_temp_dir(), 'bklx');
        $w = new Writer();
        $w->openToFile($tmp);
        $w->addRow(Row::fromValues(array_map(static fn ($c) => $all[$c], $cols), (new Style())->setFontBold()));
        $kc = static fn (int $m) => round($m / 100, 2);
        foreach ($rows as $r) {
            $line = [];
            foreach ($cols as $c) {
                $line[] = match ($c) {
                    'vs' => $r['vs'], 'bib' => $r['bib'] === null ? '' : (int) $r['bib'],
                    'last_name' => $r['last_name'], 'first_name' => $r['first_name'],
                    'birth_date' => Util::fmtDate($r['birth_date']), 'rocnik' => (int) substr((string) $r['birth_date'], 0, 4),
                    'gender' => $r['gender'] === 'Z' ? 'Ž' : 'M', 'category' => (string) $r['cat_code'],
                    'race' => Util::tr($r['race_name']), 'club' => (string) $r['club'], 'country' => $r['country'],
                    'email' => $r['email'], 'phone' => (string) $r['phone'],
                    'status' => self::STATUSES[$r['status']] ?? $r['status'], 'payment_status' => self::PAY[$r['payment_status']] ?? $r['payment_status'],
                    'price_base' => $kc((int) $r['price_base']), 'discount' => $kc((int) $r['age_discount'] + (int) $r['coupon_discount']),
                    'coupon' => (string) $r['coupon_code'], 'products' => $kc((int) $r['price_products']), 'price_final' => $kc((int) $r['price_final']),
                    'charity' => $kc((int) $r['amount_charity']), 'paid' => $kc((int) $r['amount_paid']),
                    'guardian' => (string) $r['guardian_name'], 'created_at' => Util::fmtDateTime($r['created_at']),
                    'note' => (string) $r['note'], 'contact_email' => $r['contact_email'],
                    default => str_starts_with($c, 'f') ? (string) ($vals[(int) $r['id'] . ':' . (int) substr($c, 1)] ?? '') : '',
                };
            }
            $w->addRow(Row::fromValues($line));
        }
        $w->close();
        $data = (string) file_get_contents($tmp);
        @unlink($tmp);
        $this->c->app->log->audit('event', (int) $e['id'], 'export', null, ['rows' => count($rows), 'cols' => $cols], $this->c->actor());
        return new Response($data, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $e['slug'] . '-' . date('Ymd-Hi') . '.xlsx"',
        ]);
    }
}
