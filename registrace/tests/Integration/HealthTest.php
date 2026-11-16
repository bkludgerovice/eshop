<?php
declare(strict_types=1);

namespace Bkl\Tests\Integration;

use Bkl\Http\PublicController;
use Bkl\Http\Request;

final class HealthTest extends DbTestCase
{
    public function testHealthyAfterMigrations(): void
    {
        $this->app = new \Bkl\App(self::config(), $this->app->db, dirname(__DIR__, 2));
        $r = (new PublicController($this->app))->health(new Request('GET', '/health'));
        self::assertSame(200, $r->status);
        self::assertSame(['ok' => true, 'db' => true, 'schema' => true], array_intersect_key(json_decode($r->body, true), ['ok' => 1, 'db' => 1, 'schema' => 1]));
    }

    public function testReportsMissingSchemaSeparatelyFromDb(): void
    {
        $this->app->db->pdo->exec('DELETE FROM schema_migrations');
        $this->app = new \Bkl\App(self::config(), $this->app->db, dirname(__DIR__, 2));
        $r = (new PublicController($this->app))->health(new Request('GET', '/health'));
        $d = json_decode($r->body, true);
        self::assertSame(503, $r->status);
        self::assertTrue($d['db']);
        self::assertFalse($d['schema']);
    }
}
