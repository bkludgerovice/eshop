<?php
declare(strict_types=1);

namespace Bkl;

/**
 * Zálohy databáze: výpis SQL → gzip → šifrování (libsodium, heslo z config backup.passphrase).
 * Kopie: storage/backups na serveru (N dní) + e-mail mimo hosting + stažení GitHub Actions (archiv 90 dní).
 * Obnova je jen ruční (cli/tool.php restore) – nikdy automaticky nepřepisuje produkční data.
 */
final class Backup
{
    private const MAGIC = "BKLB1\n";

    public function __construct(private App $app)
    {
    }

    private function dir(): string
    {
        return $this->app->root . '/storage/backups';
    }

    public function run(string $reason = 'denni'): string
    {
        $pass = $this->app->config->str('backup.passphrase');
        if (strlen($pass) < 12) {
            throw new \RuntimeException('V config.php chybí backup.passphrase (min. 12 znaků) – záloha nelze zašifrovat.');
        }
        $sql = $this->dump();
        $data = self::encrypt((string) gzencode($sql, 9), $pass);
        @mkdir($this->dir(), 0775, true);
        $name = 'bkl-registrace-' . date('Ymd-His') . '-' . $reason . '.sql.gz.enc';
        file_put_contents($this->dir() . '/' . $name, $data);
        $this->prune();
        $counts = $this->app->db->one('SELECT (SELECT COUNT(*) FROM registrations) regs, (SELECT COUNT(*) FROM orders) orders, (SELECT COUNT(*) FROM payments) payments');
        $to = $this->app->config->str('backup.email');
        if ($to !== '' && $reason === 'denni') {
            $this->app->mailer->sendNow(
                $to,
                'BKL Registrace – záloha ' . date('j. n. Y'),
                '<p>Šifrovaná záloha databáze registrací. Registrací: ' . (int) $counts['regs'] . ', objednávek: ' . (int) $counts['orders'] . ', plateb: ' . (int) $counts['payments'] . '.</p>'
                . '<p>Obnova podle dokumentace docs/registrace/provoz.md (kapitola Obnova ze zálohy). Heslo k záloze je uložené mimo server.</p>',
                [$name => $data]
            );
        }
        return sprintf('%s (%d kB, registrací %d)', $name, (int) ceil(strlen($data) / 1024), (int) $counts['regs']);
    }

    public function latestFile(): ?string
    {
        $files = glob($this->dir() . '/*.sql.gz.enc') ?: [];
        rsort($files);
        return $files[0] ?? null;
    }

    private function prune(): void
    {
        $keep = max(3, (int) $this->app->config->get('backup.keep_days', 14));
        foreach (glob($this->dir() . '/*.sql.gz.enc') ?: [] as $f) {
            if (filemtime($f) < time() - $keep * 86400) {
                @unlink($f);
            }
        }
    }

    /** Výpis celé databáze jako SQL (bez závislosti na mysqldump – na webhostingu není). */
    public function dump(): string
    {
        $pdo = $this->app->db->pdo;
        $out = "-- BKL Registrace záloha " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
        $tables = $this->app->db->all("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        foreach ($tables as $t) {
            $name = (string) array_values($t)[0];
            $create = $this->app->db->one('SHOW CREATE TABLE `' . str_replace('`', '', $name) . '`');
            $out .= "DROP TABLE IF EXISTS `$name`;\n" . array_values($create)[1] . ";\n";
            $st = $pdo->query('SELECT * FROM `' . str_replace('`', '', $name) . '`', \PDO::FETCH_ASSOC);
            $batch = [];
            foreach ($st as $row) {
                $vals = array_map(static fn ($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote((string) $v)), array_values($row));
                $batch[] = '(' . implode(',', $vals) . ')';
                if (count($batch) >= 200) {
                    $out .= "INSERT INTO `$name` VALUES " . implode(",\n", $batch) . ";\n";
                    $batch = [];
                }
            }
            if ($batch) {
                $out .= "INSERT INTO `$name` VALUES " . implode(",\n", $batch) . ";\n";
            }
            $out .= "\n";
        }
        return $out . "SET FOREIGN_KEY_CHECKS=1;\n";
    }

    public static function encrypt(string $plain, string $pass): string
    {
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $key = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $pass, $salt, SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::MAGIC . $salt . $nonce . sodium_crypto_secretbox($plain, $nonce, $key);
    }

    public static function decrypt(string $data, string $pass): string
    {
        if (!str_starts_with($data, self::MAGIC)) {
            throw new \RuntimeException('Soubor není záloha BKL Registrace.');
        }
        $o = strlen(self::MAGIC);
        $salt = substr($data, $o, SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $o += SODIUM_CRYPTO_PWHASH_SALTBYTES;
        $nonce = substr($data, $o, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $o += SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        $key = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $pass, $salt, SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        $plain = sodium_crypto_secretbox_open(substr($data, $o), $nonce, $key);
        if ($plain === false) {
            throw new \RuntimeException('Špatné heslo nebo poškozená záloha.');
        }
        return $plain;
    }

    /** Načte zálohu do zadané databáze (používá cli/tool.php a test obnovy). */
    public static function restoreInto(Db $db, string $sql): void
    {
        foreach (self::splitDump($sql) as $stmt) {
            $db->pdo->exec($stmt);
        }
    }

    /** @return list<string> */
    public static function splitDump(string $sql): array
    {
        // Výpis má každý příkaz ukončený „;\n“ mimo řetězce – projdeme znak po znaku kvůli středníkům v textech.
        $out = [];
        $buf = '';
        $len = strlen($sql);
        $inStr = false;
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            $buf .= $c;
            if ($inStr) {
                if ($c === '\\') {
                    $buf .= $sql[++$i] ?? '';
                } elseif ($c === "'") {
                    $inStr = false;
                }
                continue;
            }
            if ($c === "'") {
                $inStr = true;
            } elseif ($c === '-' && ($sql[$i + 1] ?? '') === '-' && ($i === 0 || $sql[$i - 1] === "\n")) {
                $nl = strpos($sql, "\n", $i);
                $buf = substr($buf, 0, -1);
                $i = $nl === false ? $len : $nl;
            } elseif ($c === ';' && (($sql[$i + 1] ?? "\n") === "\n")) {
                $s = trim(substr($buf, 0, -1));
                if ($s !== '') {
                    $out[] = $s;
                }
                $buf = '';
            }
        }
        if (trim($buf) !== '') {
            $out[] = trim($buf);
        }
        return $out;
    }
}
