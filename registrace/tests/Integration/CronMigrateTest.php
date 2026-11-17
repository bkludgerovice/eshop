<?php
declare(strict_types=1);

namespace Bkl\Tests\Integration;

use Bkl\App;
use Bkl\Http\CronController;
use Bkl\Http\Request;

/** První nasazení: prázdná databáze → migrace musí projít (bez zálohy), opakované volání nic nezmění. */
final class CronMigrateTest extends DbTestCase
{
    private function call(App $app, string $task): array
    {
        $r = (new CronController($app))->handle(new Request('GET', '/cron', ['task' => $task, 'key' => str_repeat('c', 30)]));
        self::assertSame(200, $r->status, $r->body);
        return json_decode($r->body, true);
    }

    public function testMigrateOnEmptyDatabase(): void
    {
        $db = $this->app->db;
        $db->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($db->all("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'") as $t) {
            $db->pdo->exec('DROP TABLE `' . array_values($t)[0] . '`');
        }
        $db->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $root = sys_get_temp_dir() . '/bkl-migrate-' . getmypid();
        @mkdir($root . '/storage/backups', 0777, true);
        symlink(dirname(__DIR__, 2) . '/migrations', $root . '/migrations');
        $app = new App(self::config(), $db, $root);

        $d = $this->call($app, 'migrate');
        self::assertNull($d['backup'], 'prázdná DB se nezálohuje');
        self::assertSame(['001_init'], $d['applied']);
        self::assertSame([], $this->call($app, 'status')['pending']);

        $d = $this->call($app, 'migrate');
        self::assertSame([], $d['applied']);
        @unlink($root . '/migrations');
    }

    public function testWrongKeyRejected(): void
    {
        $r = (new CronController($this->app))->handle(new Request('GET', '/cron', ['task' => 'status', 'key' => 'spatny-klic-spatny-klic-123']));
        self::assertSame(403, $r->status);
    }
}
