<?php
declare(strict_types=1);

namespace Bkl\Domain;

use Bkl\Db;
use Bkl\Util;

/** Načtení akce se vším, co potřebuje formulář a výpočet ceny. */
final class EventRepo
{
    public function __construct(private Db $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function bySlug(string $slug): ?array
    {
        $e = $this->db->one('SELECT * FROM events WHERE slug = ?', [$slug]);
        return $e ? $this->hydrate($e) : null;
    }

    /** @return array<string,mixed>|null */
    public function byId(int $id): ?array
    {
        $e = $this->db->one('SELECT * FROM events WHERE id = ?', [$id]);
        return $e ? $this->hydrate($e) : null;
    }

    /** @param array<string,mixed> $e @return array<string,mixed> */
    private function hydrate(array $e): array
    {
        $id = (int) $e['id'];
        $e['races'] = $this->db->all('SELECT * FROM races WHERE event_id = ? AND active = 1 ORDER BY sort_order, id', [$id]);
        $e['tiers'] = $this->db->all('SELECT * FROM price_tiers WHERE event_id = ? ORDER BY valid_from IS NULL, valid_from, id', [$id]);
        $e['age_rules'] = $this->db->all('SELECT * FROM age_discounts WHERE event_id = ? ORDER BY id', [$id]);
        $fields = $this->db->all('SELECT * FROM form_fields WHERE event_id = ? AND active = 1 ORDER BY sort_order, id', [$id]);
        $opts = $this->db->all(
            'SELECT o.* FROM form_field_options o JOIN form_fields f ON f.id = o.field_id WHERE f.event_id = ? AND o.active = 1 ORDER BY o.sort_order, o.id',
            [$id]
        );
        foreach ($fields as &$f) {
            $f['options'] = array_values(array_filter($opts, static fn ($o) => (int) $o['field_id'] === (int) $f['id']));
        }
        unset($f);
        $e['fields'] = $fields;
        $e['charity'] = $this->db->one('SELECT * FROM charity_campaigns WHERE event_id = ? AND active = 1 ORDER BY id LIMIT 1', [$id]);
        $e['bank'] = $this->db->one('SELECT * FROM bank_accounts WHERE id = ?', [(int) $e['bank_account_id']]);
        $e['year'] = (int) substr((string) $e['starts_at'], 0, 4);
        return $e;
    }

    /** Počty obsazených míst (aktivní registrace = čeká na platbu, potvrzená, ke kontrole). @return array{event:int,races:array<int,int>} */
    public function occupancy(int $eventId): array
    {
        $rows = $this->db->all(
            "SELECT race_id, COUNT(*) n FROM registrations WHERE event_id = ? AND status IN ('ceka_na_platbu','potvrzena','ke_kontrole') GROUP BY race_id",
            [$eventId]
        );
        $races = [];
        $total = 0;
        foreach ($rows as $r) {
            $races[(int) $r['race_id']] = (int) $r['n'];
            $total += (int) $r['n'];
        }
        return ['event' => $total, 'races' => $races];
    }

    /**
     * Stav registrací pro veřejnost.
     * @param array<string,mixed> $e
     * @return array{open:bool,reason:string,free:?int}
     */
    public function availability(array $e, ?\DateTimeImmutable $now = null, string $channel = 'online'): array
    {
        $now ??= Util::now();
        $occ = $this->occupancy((int) $e['id']);
        $free = $e['capacity'] !== null ? max(0, (int) $e['capacity'] - $occ['event']) : null;
        if ($channel === 'online') {
            if ($e['status'] !== 'otevreno') {
                $map = ['pripravovana' => 'Registrace zatím nejsou otevřené.', 'pozastaveno' => 'Registrace jsou dočasně pozastavené.',
                    'uzavreno' => 'Registrace jsou uzavřené.', 'ukoncena' => 'Akce už proběhla.', 'archivovana' => 'Akce už proběhla.'];
                return ['open' => false, 'reason' => $map[$e['status']] ?? 'Registrace nejsou otevřené.', 'free' => $free];
            }
            if ($e['reg_opens_at'] && $now < Util::dt($e['reg_opens_at'])) {
                return ['open' => false, 'reason' => 'Registrace se otevřou ' . Util::fmtDateTime($e['reg_opens_at']) . '.', 'free' => $free];
            }
            if ($e['reg_closes_at'] && $now > Util::dt($e['reg_closes_at'])) {
                return ['open' => false, 'reason' => 'Online registrace byly ukončeny.', 'free' => $free];
            }
        }
        if ($free === 0) {
            return ['open' => false, 'reason' => 'Kapacita závodu je naplněna.', 'free' => 0];
        }
        return ['open' => true, 'reason' => '', 'free' => $free];
    }
}
