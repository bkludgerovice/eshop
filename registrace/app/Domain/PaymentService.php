<?php
declare(strict_types=1);

namespace Bkl\Domain;

use Bkl\Db;
use Bkl\Log;
use Bkl\Util;

/**
 * Evidence plateb a přepočet stavu objednávky.
 * Každá platba je v tabulce payments jen jednou (unikátní ID pohybu z banky) → žádné duplicitní potvrzení.
 */
final class PaymentService
{
    /** @var callable|null fn(string $event, int $orderId): void */
    private $notify;

    public function __construct(private Db $db, private EventRepo $events, private Log $log, ?callable $notify = null)
    {
        $this->notify = $notify;
    }

    /**
     * Uloží příchozí pohyb z Fio. Vrací ['new'=>bool,'payment_id'=>?int,'order_id'=>?int,'status'=>string].
     * @param array{id:string,amount:int,currency:string,vs:string,date:string,account:string,name:string,message:string,raw:array<string,mixed>} $tx
     * @return array{new:bool,payment_id:?int,order_id:?int,status:string}
     */
    public function recordBankTransaction(array $tx, int $bankAccountId): array
    {
        if ($tx['amount'] <= 0) {
            return ['new' => false, 'payment_id' => null, 'order_id' => null, 'status' => 'odchozi'];
        }
        $vs = Vs::normalize($tx['vs']);
        if (!Vs::isOurs($vs)) {
            return ['new' => false, 'payment_id' => null, 'order_id' => null, 'status' => 'cizi'];
        }
        $exists = $this->db->value(
            "SELECT id FROM payments WHERE source = 'fio' AND bank_account_id = ? AND external_id = ?",
            [$bankAccountId, $tx['id']]
        );
        if ($exists) {
            return ['new' => false, 'payment_id' => (int) $exists, 'order_id' => null, 'status' => 'znama'];
        }
        $order = $this->db->one('SELECT id FROM orders WHERE vs = ?', [$vs]);
        try {
            $pid = $this->db->insert('payments', [
                'bank_account_id' => $bankAccountId,
                'source' => 'fio',
                'external_id' => $tx['id'],
                'amount' => $tx['amount'],
                'currency' => $tx['currency'] ?: 'CZK',
                'vs' => $vs,
                'counter_account' => mb_substr($tx['account'], 0, 100),
                'counter_name' => mb_substr($tx['name'], 0, 200),
                'message' => mb_substr($tx['message'], 0, 500),
                'received_at' => $tx['date'],
                'order_id' => $order ? (int) $order['id'] : null,
                'match_status' => $order ? 'sparovano' : 'nesparovano',
                'raw' => Util::json($tx['raw']),
                'created_by' => 'fio',
            ]);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                return ['new' => false, 'payment_id' => null, 'order_id' => null, 'status' => 'znama'];
            }
            throw $e;
        }
        if (!$order) {
            $this->log->system('varovani', 'platby', 'Platba ' . Util::money($tx['amount']) . " s VS $vs nepatří k žádné objednávce – spárujte ji ručně.", ['payment_id' => $pid]);
            return ['new' => true, 'payment_id' => $pid, 'order_id' => null, 'status' => 'nesparovano'];
        }
        $this->recalculate((int) $order['id'], 'fio');
        return ['new' => true, 'payment_id' => $pid, 'order_id' => (int) $order['id'], 'status' => 'sparovano'];
    }

    /** Ruční platba (hotovost na místě, převod potvrzený ručně). */
    public function addManual(int $orderId, int $amount, string $source, string $note, string $actor): int
    {
        if (!in_array($source, ['hotovost', 'rucne'], true)) {
            throw new \InvalidArgumentException('Neplatný zdroj platby.');
        }
        $order = $this->db->one('SELECT vs, currency FROM orders WHERE id = ?', [$orderId]);
        if (!$order) {
            throw new \InvalidArgumentException('Objednávka neexistuje.');
        }
        $pid = $this->db->insert('payments', [
            'bank_account_id' => null,
            'source' => $source,
            'external_id' => null,
            'amount' => $amount,
            'currency' => $order['currency'],
            'vs' => $order['vs'],
            'message' => mb_substr($note, 0, 500),
            'received_at' => Util::sqlDt(Util::now()),
            'order_id' => $orderId,
            'match_status' => 'rucne',
            'created_by' => $actor,
        ]);
        $this->log->audit('payment', $pid, 'rucni_platba', null, ['order_id' => $orderId, 'amount' => $amount, 'source' => $source, 'note' => $note], $actor);
        $this->recalculate($orderId, $actor);
        return $pid;
    }

    /** Ruční spárování nespárované platby s objednávkou. */
    public function assign(int $paymentId, int $orderId, string $actor): void
    {
        $p = $this->db->one('SELECT * FROM payments WHERE id = ?', [$paymentId]);
        if (!$p) {
            throw new \InvalidArgumentException('Platba neexistuje.');
        }
        $old = $p['order_id'] ? (int) $p['order_id'] : null;
        $this->db->update('payments', ['order_id' => $orderId, 'match_status' => 'rucne'], ['id' => $paymentId]);
        $this->log->audit('payment', $paymentId, 'sparovani', ['order_id' => $old], ['order_id' => $orderId], $actor);
        if ($old) {
            $this->recalculate($old, $actor);
        }
        $this->recalculate($orderId, $actor);
    }

    public function ignore(int $paymentId, string $actor): void
    {
        $this->db->update('payments', ['match_status' => 'ignorovano'], ['id' => $paymentId]);
        $this->log->audit('payment', $paymentId, 'ignorovat', null, null, $actor);
    }

    public function refund(int $orderId, int $amount, string $reason, string $actor): void
    {
        $this->db->insert('refunds', ['order_id' => $orderId, 'amount' => $amount, 'reason' => mb_substr($reason, 0, 500), 'created_by' => $actor]);
        $this->log->audit('order', $orderId, 'vraceni_penez', null, ['amount' => $amount, 'reason' => $reason], $actor);
        $this->recalculate($orderId, $actor);
    }

    /**
     * Přepočítá zaplacenou částku a stav objednávky a jejích registrací.
     * Opakované volání nic nezdvojí (stav se jen nastaví podle součtů).
     */
    public function recalculate(int $orderId, string $actor): void
    {
        $this->db->transaction(function () use ($orderId, $actor): void {
            $o = $this->db->one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
            if (!$o) {
                return;
            }
            // zámek akce kvůli případné obnově zrušených registrací (kapacita)
            $this->db->one('SELECT id FROM events WHERE id = ? FOR UPDATE', [(int) $o['event_id']]);
            $paid = (int) $this->db->value("SELECT COALESCE(SUM(amount),0) FROM payments WHERE order_id = ? AND match_status IN ('sparovano','rucne')", [$orderId]);
            $refunded = (int) $this->db->value('SELECT COALESCE(SUM(amount),0) FROM refunds WHERE order_id = ?', [$orderId]);
            $net = $paid - $refunded;
            $total = (int) $o['amount_total'];
            $status = match (true) {
                $refunded > 0 && $net <= 0 => 'vraceno',
                $total === 0 => 'zaplaceno',
                $net <= 0 => 'nezaplaceno',
                $net < $total => 'castecne',
                $net === $total => 'zaplaceno',
                default => 'preplaceno',
            };
            $wasPaid = in_array($o['payment_status'], ['zaplaceno', 'preplaceno'], true);
            $isPaid = in_array($status, ['zaplaceno', 'preplaceno'], true);
            $upd = ['amount_paid' => $net, 'payment_status' => $status];
            if ($isPaid && !$o['paid_at']) {
                $upd['paid_at'] = Util::sqlDt(Util::now());
            }
            $this->db->update('orders', $upd, ['id' => $orderId]);
            if ($o['payment_status'] !== $status) {
                $this->log->audit('order', $orderId, 'stav_platby', ['payment_status' => $o['payment_status']], ['payment_status' => $status, 'paid' => $net], $actor);
            }
            if ($isPaid) {
                $this->confirmRegistrations($o, $actor);
            }
            if ($status === 'castecne') {
                $this->log->system('varovani', 'platby', 'Objednávka ' . $o['vs'] . ' je zaplacená jen částečně: ' . Util::money($net) . ' z ' . Util::money($total) . '.', ['order_id' => $orderId]);
            }
            if ($status === 'preplaceno') {
                $this->log->system('varovani', 'platby', 'Objednávka ' . $o['vs'] . ' je přeplacená: ' . Util::money($net) . ' místo ' . Util::money($total) . '.', ['order_id' => $orderId]);
            }
            if ($isPaid && !$wasPaid && $this->notify) {
                ($this->notify)('platba_prijata', $orderId);
            }
        });
    }

    /** @param array<string,mixed> $o */
    private function confirmRegistrations(array $o, string $actor): void
    {
        $regs = $this->db->all('SELECT * FROM registrations WHERE order_id = ?', [(int) $o['id']]);
        $event = $this->events->byId((int) $o['event_id']);
        foreach ($regs as $r) {
            if ($r['status'] === 'ceka_na_platbu') {
                $this->setStatus($r, 'potvrzena', $actor);
            } elseif (in_array($r['status'], ['zrusena', 'expirovana'], true) && $r['anonymized_at'] === null) {
                // Pozdní platba po zrušení: obnovit, pokud je volné místo, jinak k ručnímu řešení.
                $occ = $this->events->occupancy((int) $o['event_id']);
                $raceCap = null;
                foreach ($event['races'] ?? [] as $race) {
                    if ((int) $race['id'] === (int) $r['race_id']) {
                        $raceCap = $race['capacity'];
                    }
                }
                $eventOk = $event['capacity'] === null || $occ['event'] < (int) $event['capacity'];
                $raceOk = $raceCap === null || ($occ['races'][(int) $r['race_id']] ?? 0) < (int) $raceCap;
                if ($eventOk && $raceOk) {
                    $this->setStatus($r, 'potvrzena', $actor . ' (pozdní platba, obnoveno)');
                } else {
                    $this->setStatus($r, 'ke_kontrole', $actor . ' (pozdní platba, plno)');
                    $this->log->system('varovani', 'platby', 'Pozdní platba ke zrušené registraci ' . $r['first_name'] . ' ' . $r['last_name'] . ' (VS ' . $o['vs'] . '), ale kapacita je plná – rozhodněte ručně.', ['registration_id' => (int) $r['id']]);
                }
            }
        }
    }

    /** @param array<string,mixed> $r */
    public function setStatus(array $r, string $status, string $actor): void
    {
        if ($r['status'] === $status) {
            return;
        }
        $this->db->update('registrations', ['status' => $status], ['id' => (int) $r['id']]);
        $this->log->audit('registration', (int) $r['id'], 'stav', ['status' => $r['status']], ['status' => $status], $actor);
    }
}
