<?php
declare(strict_types=1);

namespace Bkl\Tests\Integration;

use Bkl\App;
use Bkl\Config;
use Bkl\Db;
use Bkl\Migrator;
use Bkl\Util;
use PHPUnit\Framework\TestCase;

/** Testy proti skutečné MariaDB (lokálně i v GitHub Actions). Databáze se před každým testem vytvoří znovu. */
abstract class DbTestCase extends TestCase
{
    protected App $app;
    protected int $eventId;
    protected int $race10;
    protected int $race3;

    public static function config(): Config
    {
        return new Config([
            'env' => 'test',
            'base_url' => 'https://example.test/registrace',
            'app_key' => str_repeat('k', 40),
            'cron_key' => str_repeat('c', 30),
            'db' => [
                'host' => getenv('BKL_TEST_DB_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('BKL_TEST_DB_PORT') ?: 3306),
                'name' => getenv('BKL_TEST_DB_NAME') ?: 'bkl_test',
                'user' => getenv('BKL_TEST_DB_USER') ?: 'bkl',
                'password' => getenv('BKL_TEST_DB_PASSWORD') ?: 'bkl',
            ],
            'mail' => ['transport' => 'null', 'test_redirect' => 'test@example.test', 'copy_to' => 'kopie@example.test'],
            'fio' => ['klub' => 'TESTTOKEN'],
            'alerts' => ['admin_email' => ''],
            'backup' => ['passphrase' => 'test-passphrase-123', 'email' => ''],
        ]);
    }

    public static function connect(): Db
    {
        return Db::fromConfig(self::config());
    }

    protected function setUp(): void
    {
        $db = self::connect();
        $db->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($db->all("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'") as $t) {
            $db->pdo->exec('DROP TABLE `' . array_values($t)[0] . '`');
        }
        $db->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        (new Migrator($db, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $root = sys_get_temp_dir() . '/bkl-test-' . getmypid();
        @mkdir($root . '/storage/backups', 0777, true);
        @mkdir($root . '/storage/mail', 0777, true);
        $this->app = new App(self::config(), $db, $root);
        $this->seedEvent();
    }

    protected function seedEvent(int $capacity = 170): void
    {
        $db = $this->app->db;
        $this->eventId = $db->insert('events', [
            'slug' => 'zimni-beh-2027', 'vs_code' => 1, 'name' => Util::json(['cs' => 'Zimní běh 2027']),
            'starts_at' => '2027-01-24 10:00:00', 'status' => 'otevreno', 'capacity' => $capacity, 'payment_due_days' => 5,
            'overdue_action' => 'zrusit', 'overdue_grace_days' => 2, 'reminder_days_before_due' => 1,
        ]);
        $this->race10 = $db->insert('races', ['event_id' => $this->eventId, 'name' => Util::json(['cs' => '10 km']), 'sort_order' => 1]);
        $this->race3 = $db->insert('races', ['event_id' => $this->eventId, 'name' => Util::json(['cs' => '3 km']), 'sort_order' => 2]);
        $db->insert('price_tiers', ['event_id' => $this->eventId, 'amount' => 17000, 'channel' => 'online']);
        $db->insert('age_discounts', ['event_id' => $this->eventId, 'name' => 'Děti', 'age_max' => 15, 'percent' => 50]);
        $db->insert('age_discounts', ['event_id' => $this->eventId, 'name' => 'Senioři', 'age_min' => 60, 'percent' => 50]);
        $db->insert('coupons', ['event_id' => $this->eventId, 'code' => 'CLENBKL', 'kind' => 'clensky_spolecny', 'percent' => 50]);
        $db->insert('coupons', ['event_id' => $this->eventId, 'code' => 'PARTNER-AAA111', 'kind' => 'jednorazovy', 'percent' => 100, 'max_uses' => 1]);
        $db->insert('charity_campaigns', ['event_id' => $this->eventId, 'purpose' => Util::json(['cs' => 'Šimon a Matěj']), 'recipient' => 'Rýmlovi']);
    }

    protected function event(): array
    {
        return $this->app->events->byId($this->eventId);
    }

    /** @param array<string,mixed> $over */
    protected function runner(array $over = []): array
    {
        static $n = 0;
        $n++;
        return $over + [
            'race_id' => $this->race10, 'first_name' => 'Jan', 'last_name' => 'Tester' . $n, 'birth_date' => '1985-04-12',
            'gender' => 'M', 'email' => "jan$n@example.test", 'phone' => '602123456', 'club' => 'BK Ludgeřovice', 'country' => 'CZ',
        ];
    }

    /** @param list<array<string,mixed>> $runners */
    protected function register(array $runners, array $extra = []): array
    {
        return $this->app->registrations->register($this->event(), $extra + [
            'runners' => $runners, 'consent_terms' => '1', 'consent_gdpr' => '1',
        ], '127.0.0.1');
    }
}
