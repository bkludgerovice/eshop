<?php
declare(strict_types=1);

namespace Bkl\Tests\Integration;

use Bkl\Backup;

final class BackupTest extends DbTestCase
{
    public function testBackupRestoreRoundTrip(): void
    {
        $this->register([$this->runner(['last_name' => "O'Neill; DROP"]), $this->runner()]);
        $b = new Backup($this->app);
        $b->run('test');
        $file = $b->latestFile();
        self::assertNotNull($file);
        $sql = (string) gzdecode(Backup::decrypt((string) file_get_contents($file), 'test-passphrase-123'));
        self::assertStringContainsString('CREATE TABLE `registrations`', $sql);

        $before = (int) $this->app->db->value('SELECT COUNT(*) FROM registrations');
        $this->app->db->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->app->db->pdo->exec('DELETE FROM registrations');
        Backup::restoreInto($this->app->db, $sql);
        self::assertSame($before, (int) $this->app->db->value('SELECT COUNT(*) FROM registrations'));
        self::assertSame(1, (int) $this->app->db->value("SELECT COUNT(*) FROM registrations WHERE last_name = 'O''Neill; DROP'"));
    }

    public function testWrongPassphraseFails(): void
    {
        $enc = Backup::encrypt('data', 'heslo-spravne-123');
        $this->expectException(\RuntimeException::class);
        Backup::decrypt($enc, 'spatne-heslo');
    }
}
