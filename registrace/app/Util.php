<?php
declare(strict_types=1);

namespace Bkl;

final class Util
{
    public const TZ = 'Europe/Prague';

    public static function tz(): \DateTimeZone
    {
        return new \DateTimeZone(self::TZ);
    }

    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', self::tz());
    }

    public static function dt(?string $v): ?\DateTimeImmutable
    {
        if ($v === null || $v === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($v, self::tz());
        } catch (\Exception) {
            return null;
        }
    }

    public static function sqlDt(\DateTimeInterface $d): string
    {
        return $d->format('Y-m-d H:i:s');
    }

    public static function e(mixed $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Částka v haléřích → „1 234 Kč“ (nebo s haléři, pokud nejsou nulové). */
    public static function money(int $minor, string $currency = 'CZK'): string
    {
        $neg = $minor < 0;
        $minor = abs($minor);
        $whole = intdiv($minor, 100);
        $cents = $minor % 100;
        $s = number_format($whole, 0, ',', "\u{00a0}");
        if ($cents !== 0) {
            $s .= ',' . str_pad((string) $cents, 2, '0', STR_PAD_LEFT);
        }
        $sym = ['CZK' => 'Kč', 'EUR' => '€', 'PLN' => 'zł'][$currency] ?? $currency;
        return ($neg ? '−' : '') . $s . "\u{00a0}" . $sym;
    }

    /** „170“ nebo „170,50“ → haléře; neplatný vstup → null. */
    public static function parseMoney(string $s): ?int
    {
        $s = str_replace([' ', "\u{00a0}", 'Kč', 'kč'], '', trim($s));
        $s = str_replace(',', '.', $s);
        if ($s === '' || !preg_match('/^-?\d+(\.\d{1,2})?$/', $s)) {
            return null;
        }
        return (int) round(((float) $s) * 100);
    }

    public static function token(int $len = 32): string
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < $len; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }

    /** Krátký kód pro prezenci (bez zaměnitelných znaků). */
    public static function code(int $len = 10): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }

    /** Text bez diakritiky, malými písmeny (vyhledávání, porovnání jmen). */
    public static function fold(string $s): string
    {
        $t = \Normalizer::normalize($s, \Normalizer::FORM_D);
        $t = preg_replace('/\p{Mn}+/u', '', $t === false ? $s : $t) ?? $s;
        return mb_strtolower($t, 'UTF-8');
    }

    public static function ascii(string $s): string
    {
        $t = \Normalizer::normalize($s, \Normalizer::FORM_D);
        $t = preg_replace('/\p{Mn}+/u', '', $t === false ? $s : $t) ?? $s;
        return preg_replace('/[^\x20-\x7E]/', '', $t) ?? '';
    }

    public static function clean(mixed $v, int $max): string
    {
        $s = is_scalar($v) ? (string) $v : '';
        $s = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $s) ?? '';
        return mb_substr(trim($s), 0, $max, 'UTF-8');
    }

    /** Text z JSON sloupce {"cs": "..."} v požadovaném jazyce (fallback čeština). */
    public static function tr(mixed $json, string $lang = 'cs'): string
    {
        if (is_string($json)) {
            $d = json_decode($json, true);
            if (!is_array($d)) {
                return $json;
            }
            $json = $d;
        }
        if (!is_array($json)) {
            return '';
        }
        return (string) ($json[$lang] ?? $json['cs'] ?? (reset($json) ?: ''));
    }

    public static function json(mixed $v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function isEmail(string $s): bool
    {
        return (bool) preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/', $s) && filter_var($s, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function fmtDate(?string $d): string
    {
        $x = self::dt($d);
        return $x ? $x->format('j. n. Y') : '';
    }

    public static function fmtDateTime(?string $d): string
    {
        $x = self::dt($d);
        return $x ? $x->format('j. n. Y H:i') : '';
    }
}
