<?php
declare(strict_types=1);

namespace Bkl\Domain;

/**
 * Variabilní symboly registrací: 9 číslic „8 RR AA PPPP“
 *   8    – registrace (ples používá 2701xxx, e-shop platby nepřijímá)
 *   RR   – rok konání akce (27 = 2027)
 *   AA   – číslo akce (01–99), nastavuje se u akce
 *   PPPP – pořadí objednávky v akci (0001–9999)
 */
final class Vs
{
    public const PREFIX = '8';

    public static function make(int $eventYear, int $eventCode, int $seq): string
    {
        if ($eventCode < 1 || $eventCode > 99) {
            throw new \InvalidArgumentException('Číslo akce pro VS musí být 1–99.');
        }
        if ($seq < 1 || $seq > 9999) {
            throw new \OverflowException('Vyčerpána řada variabilních symbolů akce (max. 9999 objednávek).');
        }
        return self::PREFIX . sprintf('%02d%02d%04d', $eventYear % 100, $eventCode, $seq);
    }

    /** Patří VS registracím? (ostatní platby na klubový účet ignorujeme) */
    public static function isOurs(?string $vs): bool
    {
        return $vs !== null && (bool) preg_match('/^8\d{8}$/', self::normalize($vs));
    }

    public static function normalize(?string $vs): string
    {
        return ltrim(preg_replace('/\D/', '', (string) $vs) ?? '', '0');
    }
}
