<?php
declare(strict_types=1);

namespace Bkl;

/** Ochrana formulářů bez cookies (iframe v Safari cookies blokuje): podepsaný čas + skryté pole + limit. */
final class Security
{
    public function __construct(private Config $config, private Db $db)
    {
    }

    private function key(): string
    {
        $k = $this->config->str('app_key');
        if (strlen($k) < 32) {
            throw new \RuntimeException('V config.php chybí app_key (alespoň 32 znaků).');
        }
        return $k;
    }

    public function formToken(string $scope, ?int $time = null): string
    {
        $t = (string) ($time ?? time());
        return $t . '.' . substr(hash_hmac('sha256', $scope . '|' . $t, $this->key()), 0, 32);
    }

    /** Vrací chybovou hlášku, nebo null když je vše v pořádku. */
    public function checkFormToken(string $scope, string $token, int $minSeconds = 3, int $maxSeconds = 86400): ?string
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || !ctype_digit($parts[0])) {
            return 'Formulář vypršel, načtěte prosím stránku znovu.';
        }
        $expected = substr(hash_hmac('sha256', $scope . '|' . $parts[0], $this->key()), 0, 32);
        if (!hash_equals($expected, $parts[1])) {
            return 'Formulář vypršel, načtěte prosím stránku znovu.';
        }
        $age = time() - (int) $parts[0];
        if ($age < $minSeconds) {
            return 'Formulář byl odeslán příliš rychle. Zkuste to prosím znovu.';
        }
        if ($age > $maxSeconds) {
            return 'Formulář vypršel, načtěte prosím stránku znovu.';
        }
        return null;
    }

    /** Jednoduchý limit počtu požadavků v okně (např. 10 registrací z jedné IP za 10 minut). */
    public function rateLimit(string $bucket, int $max, int $windowSeconds): bool
    {
        $start = date('Y-m-d H:i:s', intdiv(time(), $windowSeconds) * $windowSeconds);
        $b = substr($bucket, 0, 120);
        $this->db->run('INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1', [$b, $start]);
        $hits = (int) $this->db->value('SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?', [$b, $start]);
        if (random_int(1, 50) === 1) {
            $this->db->run('DELETE FROM rate_limits WHERE window_start < ?', [date('Y-m-d H:i:s', time() - 86400)]);
        }
        return $hits <= $max;
    }

    public function hmac(string $data): string
    {
        return hash_hmac('sha256', $data, $this->key());
    }
}
