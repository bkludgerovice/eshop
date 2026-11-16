<?php
declare(strict_types=1);

/*
 * Ověření zálohy (GitHub Actions): rozšifruje zálohu, obnoví ji do PRÁZDNÉ testovací databáze a vypíše počty.
 *   BKL_BACKUP_PASSPHRASE=… BKL_DB_HOST=127.0.0.1 BKL_DB_NAME=restore_test BKL_DB_USER=root BKL_DB_PASSWORD=root php cli/restore-check.php zaloha.enc
 * Nikdy nespouštějte proti produkční databázi.
 */

require __DIR__ . '/../vendor/autoload.php';

use Bkl\Backup;
use Bkl\Config;
use Bkl\Db;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$file = $argv[1] ?? '';
$pass = (string) getenv('BKL_BACKUP_PASSPHRASE');
if (!is_file($file) || $pass === '') {
    fwrite(STDERR, "Chybí soubor zálohy nebo BKL_BACKUP_PASSPHRASE\n");
    exit(1);
}
$db = Db::fromConfig(new Config(['db' => [
    'host' => getenv('BKL_DB_HOST') ?: '127.0.0.1', 'port' => (int) (getenv('BKL_DB_PORT') ?: 3306),
    'name' => getenv('BKL_DB_NAME') ?: 'restore_test', 'user' => getenv('BKL_DB_USER') ?: 'root', 'password' => getenv('BKL_DB_PASSWORD') ?: '',
]]));
if ((int) $db->value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()') > 0) {
    fwrite(STDERR, "Cílová databáze není prázdná – ukončeno pro jistotu.\n");
    exit(1);
}
$sql = gzdecode(Backup::decrypt((string) file_get_contents($file), $pass));
if ($sql === false) {
    fwrite(STDERR, "Záloha je poškozená.\n");
    exit(1);
}
Backup::restoreInto($db, $sql);
$c = $db->one('SELECT (SELECT COUNT(*) FROM events) akce, (SELECT COUNT(*) FROM registrations) registrace, (SELECT COUNT(*) FROM orders) objednavky, (SELECT COUNT(*) FROM payments) platby');
echo "Obnova OK: akce {$c['akce']}, registrace {$c['registrace']}, objednávky {$c['objednavky']}, platby {$c['platby']}\n";
