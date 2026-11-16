<?php
declare(strict_types=1);

namespace Bkl\Domain;

/**
 * Výpočet ceny jednoho závodníka. Čisté funkce bez databáze – snadno testovatelné.
 *
 * Pravidla (doplněk zadání, bod 5):
 *  - cenová hladina podle data registrace a kanálu (online / na místě), tratě mají přednost před obecnou hladinou,
 *  - věk se počítá podle ročníku narození: rok akce − rok narození,
 *  - slevy se nesčítají: uplatní se vyšší z věkové slevy a kuponu,
 *  - sleva se standardně vztahuje jen na startovné (ne na produkty), lze změnit u akce.
 */
final class Pricing
{
    public static function ageByYear(string $birthDate, int $eventYear): int
    {
        return $eventYear - (int) substr($birthDate, 0, 4);
    }

    /** Přesný věk v den akce (pro souhlas rodiče u nezletilých). */
    public static function exactAge(string $birthDate, \DateTimeInterface $eventDate): int
    {
        $b = new \DateTimeImmutable($birthDate);
        return $b->diff(new \DateTimeImmutable($eventDate->format('Y-m-d')))->y;
    }

    /**
     * @param list<array<string,mixed>> $tiers
     * @return array<string,mixed>|null
     */
    public static function pickTier(array $tiers, int $raceId, \DateTimeInterface $now, string $channel = 'online'): ?array
    {
        $at = $now->format('Y-m-d H:i:s');
        $match = static function (array $t) use ($at): bool {
            return ($t['valid_from'] === null || $t['valid_from'] === '' || $t['valid_from'] <= $at)
                && ($t['valid_to'] === null || $t['valid_to'] === '' || $t['valid_to'] >= $at);
        };
        $channels = $channel === 'na_miste' ? ['na_miste', 'online'] : ['online'];
        foreach ($channels as $ch) {
            $specific = null;
            $general = null;
            foreach ($tiers as $t) {
                if (($t['channel'] ?? 'online') !== $ch || !$match($t)) {
                    continue;
                }
                $rid = $t['race_id'] === null || $t['race_id'] === '' ? null : (int) $t['race_id'];
                if ($rid === $raceId) {
                    $specific ??= $t;
                } elseif ($rid === null) {
                    $general ??= $t;
                }
            }
            if ($specific || $general) {
                return $specific ?? $general;
            }
        }
        return null;
    }

    /** @param list<array<string,mixed>> $rules */
    public static function agePercent(array $rules, int $age): int
    {
        $best = 0;
        foreach ($rules as $r) {
            $ok = true;
            if ($r['age_max'] !== null && $r['age_max'] !== '' && $age > (int) $r['age_max']) {
                $ok = false;
            }
            if ($r['age_min'] !== null && $r['age_min'] !== '' && $age < (int) $r['age_min']) {
                $ok = false;
            }
            if (($r['age_max'] === null || $r['age_max'] === '') && ($r['age_min'] === null || $r['age_min'] === '')) {
                $ok = false;
            }
            if ($ok) {
                $best = max($best, (int) $r['percent']);
            }
        }
        return min(100, $best);
    }

    /** @param array<string,mixed>|null $coupon */
    public static function couponAmount(?array $coupon, int $base): int
    {
        if (!$coupon) {
            return 0;
        }
        if ($coupon['percent'] !== null && $coupon['percent'] !== '') {
            return self::percentOf($base, (int) $coupon['percent']);
        }
        return min($base, max(0, (int) ($coupon['fixed_amount'] ?? 0)));
    }

    /** Procento z částky zaokrouhlené na celé koruny (100 haléřů). */
    public static function percentOf(int $amount, int $percent): int
    {
        $raw = $amount * $percent / 100;
        return (int) (round($raw / 100) * 100);
    }

    /**
     * @param array<string,mixed>|null $tier
     * @param list<array<string,mixed>> $ageRules
     * @param array<string,mixed>|null $coupon
     * @return array{base:int,age_discount:int,coupon_discount:int,products:int,final:int,age:int,age_percent:int,discount_source:string}
     */
    public static function quote(?array $tier, int $age, array $ageRules, ?array $coupon, int $products, bool $discountProducts = false): array
    {
        $base = $tier ? (int) $tier['amount'] : 0;
        $discountBase = $base + ($discountProducts ? $products : 0);
        $agePct = self::agePercent($ageRules, $age);
        $ageAmt = self::percentOf($discountBase, $agePct);
        $couponAmt = self::couponAmount($coupon, $discountBase);
        // Nesčítají se – vyhrává vyšší sleva. Při shodě má přednost kupon (evidujeme jeho použití).
        if ($couponAmt >= $ageAmt && $couponAmt > 0) {
            $ageAmt = 0;
            $src = 'kupon';
        } else {
            $couponAmt = 0;
            $src = $ageAmt > 0 ? 'vek' : '';
        }
        $final = max(0, $base + $products - $ageAmt - $couponAmt);
        return [
            'base' => $base,
            'age_discount' => $ageAmt,
            'coupon_discount' => $couponAmt,
            'products' => $products,
            'final' => $final,
            'age' => $age,
            'age_percent' => $agePct,
            'discount_source' => $src,
        ];
    }
}
