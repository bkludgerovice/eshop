<?php
declare(strict_types=1);

namespace Bkl\Tests\Integration;

use Bkl\ValidationException;

final class RegistrationTest extends DbTestCase
{
    public function testSingleRegistrationCreatesOrderWithVsAndEmail(): void
    {
        $o = $this->register([$this->runner()]);
        self::assertSame('827010001', $o['vs']);
        self::assertSame(17000, (int) $o['amount_total']);
        self::assertSame('nezaplaceno', $o['payment_status']);
        self::assertSame('2502518589/2010', $o['pay_account']);
        $reg = $this->app->db->one('SELECT * FROM registrations WHERE order_id = ?', [(int) $o['id']]);
        self::assertSame('ceka_na_platbu', $reg['status']);
        self::assertSame(3, (int) $this->app->db->value('SELECT COUNT(*) FROM consents WHERE registration_id = ?', [(int) $reg['id']]));
        $mail = $this->app->db->one('SELECT * FROM email_queue WHERE order_id = ?', [(int) $o['id']]);
        self::assertNotNull($mail, 'potvrzovací e-mail je ve frontě');
        self::assertSame('kopie@example.test', $mail['bcc'], 'kopie potvrzení jde do registrace@ (druhá stopa)');
        self::assertStringContainsString('827010001', $mail['body_html']);
        self::assertSame('827010002', $this->register([$this->runner()])['vs']);
    }

    public function testMultipleRunnersOnePaymentAndCharity(): void
    {
        $o = $this->register([
            $this->runner(),
            $this->runner(['race_id' => $this->race3, 'birth_date' => '2014-06-02', 'guardian_name' => 'Jan Tester', 'guardian_consent' => '1']),
            $this->runner(['birth_date' => '1967-01-01', 'coupon' => 'clenbkl']),
        ], ['charity' => '100']);
        // 170 + 85 (dítě) + 85 (senior, kupon 50 % se nesčítá) + 100 charita
        self::assertSame(17000 + 8500 + 8500 + 10000, (int) $o['amount_total']);
        self::assertSame(10000, (int) $o['amount_charity']);
        self::assertSame(3, (int) $this->app->db->value('SELECT COUNT(*) FROM registrations WHERE order_id = ?', [(int) $o['id']]));
        $senior = $this->app->db->one("SELECT * FROM registrations WHERE order_id = ? AND birth_date = '1967-01-01'", [(int) $o['id']]);
        self::assertSame(8500, (int) $senior['coupon_discount'] + (int) $senior['age_discount']);
    }

    public function testMinorNeedsGuardian(): void
    {
        try {
            $this->register([$this->runner(['birth_date' => '2012-12-31'])]);
            self::fail('chybí souhlas rodiče');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('runners.0.guardian_name', $e->errors);
            self::assertArrayHasKey('runners.0.guardian_consent', $e->errors);
        }
    }

    public function testConsentsRequired(): void
    {
        $this->expectException(ValidationException::class);
        $this->app->registrations->register($this->event(), ['runners' => [$this->runner()]], '');
    }

    public function testSingleUseCouponOnlyOnce(): void
    {
        $o = $this->register([$this->runner(['coupon' => 'partner-aaa111'])]);
        self::assertSame(0, (int) $o['amount_total']);
        self::assertSame('zaplaceno', $o['payment_status'], '100% kupon → rovnou potvrzeno');
        try {
            $this->register([$this->runner(['coupon' => 'PARTNER-AAA111'])]);
            self::fail('druhé použití');
        } catch (ValidationException $e) {
            self::assertStringContainsString('už byl použit', $e->errors['runners.0.coupon']);
        }
    }

    public function testMemberCouponUnlimitedAndTracked(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->register([$this->runner(['coupon' => 'CLENBKL'])]);
        }
        self::assertSame(3, (int) $this->app->db->value("SELECT used_count FROM coupons WHERE code = 'CLENBKL'"));
    }

    public function testDuplicatePersonRejected(): void
    {
        $r = $this->runner();
        $this->register([$r]);
        $this->expectException(ValidationException::class);
        $this->register([$r]);
    }

    public function testCapacityLastPlace(): void
    {
        $this->app->db->update('events', ['capacity' => 2], ['id' => $this->eventId]);
        $this->register([$this->runner()]);
        try {
            $this->register([$this->runner(), $this->runner()]);
            self::fail('2 místa nejsou');
        } catch (ValidationException $e) {
            self::assertStringContainsString('zbývá jen 1', $e->getMessage());
        }
        $this->register([$this->runner()]);
        $this->expectException(ValidationException::class);
        $this->register([$this->runner()]);
    }

    public function testClosedEventRejected(): void
    {
        $this->app->db->update('events', ['status' => 'pripravovana'], ['id' => $this->eventId]);
        $this->expectException(ValidationException::class);
        $this->register([$this->runner()]);
    }

    public function testPriceFrozenAfterTierChange(): void
    {
        $o = $this->register([$this->runner()]);
        $this->app->db->run('UPDATE price_tiers SET amount = 25000');
        $this->app->payments->recalculate((int) $o['id'], 'test');
        self::assertSame(17000, (int) $this->app->db->value('SELECT amount_total FROM orders WHERE id = ?', [(int) $o['id']]));
    }

    /** Dva souběžné procesy se snaží získat poslední místo – uspět smí jen jeden. */
    public function testConcurrentLastPlace(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl není k dispozici');
        }
        $this->app->db->update('events', ['capacity' => 1], ['id' => $this->eventId]);
        $pids = [];
        for ($i = 0; $i < 4; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $db = self::connect();
                $app = new \Bkl\App(self::config(), $db, sys_get_temp_dir());
                try {
                    $app->registrations->register($app->events->byId($this->eventId), [
                        'runners' => [$this->runner(['last_name' => 'Soubeh' . $i])], 'consent_terms' => '1', 'consent_gdpr' => '1',
                    ]);
                    exit(0);
                } catch (\Throwable) {
                    exit(1);
                }
            }
            $pids[] = $pid;
        }
        $ok = 0;
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            if (pcntl_wexitstatus($status) === 0) {
                $ok++;
            }
        }
        self::assertSame(1, $ok, 'poslední místo získal právě jeden');
        self::assertSame(1, (int) self::connect()->value('SELECT COUNT(*) FROM registrations'));
    }
}
