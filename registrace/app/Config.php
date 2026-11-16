<?php
declare(strict_types=1);

namespace Bkl;

/**
 * Konfigurace aplikace. Skutečné hodnoty (hesla, tokeny) jsou jen v config/config.php na serveru,
 * který není v GitHubu. Vzor je v config/config.example.php.
 */
final class Config
{
    /** @param array<string,mixed> $data */
    public function __construct(private array $data)
    {
    }

    public static function load(string $file): self
    {
        if (!is_file($file)) {
            throw new \RuntimeException('Chybí konfigurační soubor config/config.php (vzor: config/config.example.php).');
        }
        $data = require $file;
        if (!is_array($data)) {
            throw new \RuntimeException('config/config.php musí vracet pole.');
        }
        return new self($data);
    }

    public function get(string $path, mixed $default = null): mixed
    {
        $cur = $this->data;
        foreach (explode('.', $path) as $k) {
            if (!is_array($cur) || !array_key_exists($k, $cur)) {
                return $default;
            }
            $cur = $cur[$k];
        }
        return $cur;
    }

    public function str(string $path, string $default = ''): string
    {
        $v = $this->get($path, $default);
        return is_scalar($v) ? (string) $v : $default;
    }

    public function isProduction(): bool
    {
        return $this->str('env', 'prod') === 'prod';
    }

    /** Veřejná adresa aplikace bez lomítka na konci, např. https://eshop.bkludgerovice.cz/registrace */
    public function baseUrl(): string
    {
        return rtrim($this->str('base_url'), '/');
    }

    /** Cesta v URL, např. /registrace */
    public function basePath(): string
    {
        $p = parse_url($this->baseUrl(), PHP_URL_PATH);
        return rtrim(is_string($p) ? $p : '', '/');
    }

    /** @param array<string,mixed> $override */
    public function with(array $override): self
    {
        return new self(array_replace_recursive($this->data, $override));
    }
}
