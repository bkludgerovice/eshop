<?php
declare(strict_types=1);

namespace Bkl\Tests\Integration;

use Bkl\Domain\FioClient;
use Bkl\Jobs;
use Bkl\Util;

final class PaymentTest extends DbTestCase
{
    private function tx(string $id, int $amount, string $vs): array
    {
        return ['id' => $id, 'amount' => $amount, 'currency' => 'CZK', 'vs' => $vs, 'date' => '2026-11-20 00:00:00', 'account' => '1/0800', 'name' => 'Plátce', 'message' => '', 'raw' => []];
    }

    public function testExactPaymentConfirmsAndSendsEmail(): void
    {
        $o = $this->register([$this->runner(), $this->runner()]);
        $r = $this->app->payments->recordBankTransaction($this->tx('1', 34000, $o['vs']), 1);
        self::assertSame('sparovano', $r['status']);
        $o2 = $this->app->db->one('SELECT * FROM orders WHERE id = ?', [(int) $o['id']]);
        self::assertSame('zaplaceno', $o2['payment_status']);
        self::assertSame(2, (int) $this->app->db->value("SELECT COUNT(*) FROM registrations WHERE order_id = ? AND status = 'potvrzena'", [(int) $o['id']]));
        self::assertSame(2, (int) $this->app->db->value("SELECT COUNT(*) FROM email_queue WHERE dedupe_key LIKE 'platba_prijata-%' AND order_id = ?", [(int) $o['id']]), 'každý závodník (jiný e-mail) dostane potvrzení');
    }

    public function testDuplicateTransactionIgnored(): void
    {
        $o = $this->register([$this->runner()]);
        $this->app->payments->recordBankTransaction($this->tx('77', 17000, $o['vs']), 1);
        $r = $this->app->payments->recordBankTransaction($this->tx('77', 17000, $o['vs']), 1);
        self::assertFalse($r['new']);
        self::assertSame(17000, (int) $this->app->db->value('SELECT amount_paid FROM orders WHERE id = ?', [(int) $o['id']]));
        self::assertSame(1, (int) $this->app->db->value("SELECT COUNT(*) FROM email_queue WHERE dedupe_key LIKE 'platba_prijata-%'"));
    }

    public function testPartialAndOverpayment(): void
    {
        $o = $this->register([$this->runner()]);
        $this->app->payments->recordBankTransaction($this->tx('1', 10000, $o['vs']), 1);
        self::assertSame('castecne', $this->app->db->value('SELECT payment_status FROM orders WHERE id = ?', [(int) $o['id']]));
        self::assertSame('ceka_na_platbu', $this->app->db->value('SELECT status FROM registrations WHERE order_id = ?', [(int) $o['id']]));
        $this->app->payments->recordBankTransaction($this->tx('2', 10000, $o['vs']), 1);
        self::assertSame('preplaceno', $this->app->db->value('SELECT payment_status FROM orders WHERE id = ?', [(int) $o['id']]));
        self::assertSame('potvrzena', $this->app->db->value('SELECT status FROM registrations WHERE order_id = ?', [(int) $o['id']]));
        self::assertGreaterThan(0, (int) $this->app->db->value("SELECT COUNT(*) FROM system_log WHERE message LIKE '%přeplacená%'"));
    }

    public function testForeignAndUnknownPayments(): void
    {
        $r = $this->app->payments->recordBankTransaction($this->tx('5', 28000, '2701005'), 1);
        self::assertSame('cizi', $r['status'], 'platba plesu se ignoruje');
        $r = $this->app->payments->recordBankTransaction($this->tx('6', 17000, '827019999'), 1);
        self::assertSame('nesparovano', $r['status']);
        $o = $this->register([$this->runner()]);
        $this->app->payments->assign((int) $r['payment_id'], (int) $o['id'], 'test');
        self::assertSame('zaplaceno', $this->app->db->value('SELECT payment_status FROM orders WHERE id = ?', [(int) $o['id']]));
    }

    public function testLatePaymentRestoresIfFreeElseNeedsReview(): void
    {
        $this->app->db->update('events', ['capacity' => 1], ['id' => $this->eventId]);
        $o = $this->register([$this->runner()]);
        $reg = $this->app->db->one('SELECT * FROM registrations WHERE order_id = ?', [(int) $o['id']]);
        $this->app->payments->setStatus($reg, 'expirovana', 'test');
        $this->app->payments->recordBankTransaction($this->tx('1', 17000, $o['vs']), 1);
        self::assertSame('potvrzena', $this->app->db->value('SELECT status FROM registrations WHERE id = ?', [(int) $reg['id']]), 'volné místo → obnoveno');

        // druhý případ: místo mezitím obsadil někdo jiný
        $this->app->db->update('events', ['capacity' => 2], ['id' => $this->eventId]);
        $o2 = $this->register([$this->runner()]);
        $reg2 = $this->app->db->one('SELECT * FROM registrations WHERE order_id = ?', [(int) $o2['id']]);
        $this->app->payments->setStatus($reg2, 'expirovana', 'test');
        $this->register([$this->runner()]);
        $this->app->payments->recordBankTransaction($this->tx('2', 17000, $o2['vs']), 1);
        self::assertSame('ke_kontrole', $this->app->db->value('SELECT status FROM registrations WHERE id = ?', [(int) $reg2['id']]), 'plno → k ručnímu řešení');
    }

    public function testCashPaymentAndRefund(): void
    {
        $o = $this->register([$this->runner()]);
        $this->app->payments->addManual((int) $o['id'], 17000, 'hotovost', 'na místě', 'admin:test');
        self::assertSame('zaplaceno', $this->app->db->value('SELECT payment_status FROM orders WHERE id = ?', [(int) $o['id']]));
        $this->app->payments->refund((int) $o['id'], 17000, 'zranění', 'admin:test');
        self::assertSame('vraceno', $this->app->db->value('SELECT payment_status FROM orders WHERE id = ?', [(int) $o['id']]));
    }

    public function testFioJobWithFakeClient(): void
    {
        $o = $this->register([$this->runner()]);
        $vs = $o['vs'];
        $fake = new class ('x') extends FioClient {
            public static string $vs = '';
            protected function get(string $url): array
            {
                return [200, (string) json_encode(['accountStatement' => ['transactionList' => ['transaction' => [
                    ['column22' => ['value' => 991], 'column0' => ['value' => '2026-11-20+0100'], 'column1' => ['value' => 170], 'column5' => ['value' => self::$vs]],
                    ['column22' => ['value' => 992], 'column0' => ['value' => '2026-11-20+0100'], 'column1' => ['value' => 280], 'column5' => ['value' => '2701001']],
                ]]]])];
            }
        };
        $fake::$vs = $vs;
        $jobs = new Jobs($this->app, static fn () => $fake);
        $msg = $jobs->fio(Util::now());
        self::assertStringContainsString('spárováno 1', $msg);
        self::assertSame('zaplaceno', $this->app->db->value('SELECT payment_status FROM orders WHERE id = ?', [(int) $o['id']]));
        // hned znovu → limit 30 s, nic se nestáhne
        self::assertStringContainsString('přeskočeno', $jobs->fio(Util::now()));
    }

    public function testOverdueCancelsAndReminds(): void
    {
        $o = $this->register([$this->runner()]);
        $jobs = new Jobs($this->app);
        $due = new \DateTimeImmutable((string) $o['due_date'], Util::tz());
        self::assertStringContainsString('upomínek 1', $jobs->overdue($due->modify('-1 day')->setTime(9, 0)));
        self::assertStringContainsString('upomínek 0', $jobs->overdue($due->modify('-1 day')->setTime(10, 0)), 'upomínka jen jednou');
        self::assertStringContainsString('zrušeno 1', $jobs->overdue($due->modify('+3 days')));
        self::assertSame('expirovana', $this->app->db->value('SELECT status FROM registrations WHERE order_id = ?', [(int) $o['id']]));
    }
}
