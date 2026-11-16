<?php
declare(strict_types=1);

namespace Bkl;

/** Spouští migrace z migrations/*.sql v pořadí; každou jen jednou. Migrace nikdy nemažou data. */
final class Migrator
{
    public function __construct(private Db $db, private string $dir)
    {
    }

    /** @return list<string> nově provedené migrace */
    public function migrate(): array
    {
        $this->db->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(100) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci');
        $done = array_column($this->db->all('SELECT version FROM schema_migrations'), 'version');
        $files = glob(rtrim($this->dir, '/') . '/*.sql') ?: [];
        sort($files);
        $applied = [];
        foreach ($files as $f) {
            $v = basename($f, '.sql');
            if (in_array($v, $done, true)) {
                continue;
            }
            foreach (self::split((string) file_get_contents($f)) as $stmt) {
                $this->db->pdo->exec($stmt);
            }
            $this->db->insert('schema_migrations', ['version' => $v]);
            $applied[] = $v;
        }
        return $applied;
    }

    /** @return list<string> */
    public function pending(): array
    {
        try {
            $done = array_column($this->db->all('SELECT version FROM schema_migrations'), 'version');
        } catch (\PDOException) {
            $done = [];
        }
        $files = glob(rtrim($this->dir, '/') . '/*.sql') ?: [];
        sort($files);
        return array_values(array_filter(array_map(static fn ($f) => basename($f, '.sql'), $files), static fn ($v) => !in_array($v, $done, true)));
    }

    /** Rozdělí SQL soubor na příkazy (středník na konci řádku, komentáře -- pryč). @return list<string> */
    public static function split(string $sql): array
    {
        $lines = array_filter(explode("\n", str_replace("\r", '', $sql)), static fn ($l) => !str_starts_with(ltrim($l), '--'));
        $out = [];
        $buf = '';
        foreach ($lines as $l) {
            $buf .= $l . "\n";
            if (str_ends_with(rtrim($l), ';')) {
                $s = trim($buf);
                $out[] = rtrim($s, ';');
                $buf = '';
            }
        }
        if (trim($buf) !== '') {
            $out[] = trim($buf);
        }
        return $out;
    }
}
