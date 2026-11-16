<?php
declare(strict_types=1);

namespace Bkl\Tests\Unit;

use Bkl\Domain\Pricing;
use PHPUnit\Framework\TestCase;

final class PricingTest extends TestCase
{
    private function tiers(): array
    {
        return [
            ['id' => 1, 'race_id' => null, 'valid_from' => null, 'valid_to' => '2026-12-31 23:59:59', 'channel' => 'online', 'amount' => 17000],
            ['id' => 2, 'race_id' => null, 'valid_from' => '2027-01-01 00:00:00', 'valid_to' => null, 'channel' => 'online', 'amount' => 20000],
            ['id' => 3, 'race_id' => null, 'valid_from' => null, 'valid_to' => null, 'channel' => 'na_miste', 'amount' => 25000],
            ['id' => 4, 'race_id' => 7, 'valid_from' => null, 'valid_to' => null, 'channel' => 'online', 'amount' => 10000],
        ];
    }

    private function ageRules(): array
    {
        return [
            ['age_max' => 15, 'age_min' => null, 'percent' => 50],
            ['age_max' => null, 'age_min' => 60, 'percent' => 50],
        ];
    }

    public function testTierSwitchesByDate(): void
    {
        $dec = new \DateTimeImmutable('2026-12-31 22:00:00');
        $jan = new \DateTimeImmutable('2027-01-01 00:00:01');
        self::assertSame(1, Pricing::pickTier($this->tiers(), 5, $dec)['id']);
        self::assertSame(2, Pricing::pickTier($this->tiers(), 5, $jan)['id']);
    }

    public function testRaceSpecificTierWins(): void
    {
        self::assertSame(4, Pricing::pickTier($this->tiers(), 7, new \DateTimeImmutable('2026-12-01'))['id']);
    }

    public function testOnsiteTierAndFallback(): void
    {
        self::assertSame(3, Pricing::pickTier($this->tiers(), 5, new \DateTimeImmutable('2027-01-24'), 'na_miste')['id']);
        $onlyOnline = [$this->tiers()[0]];
        self::assertSame(1, Pricing::pickTier($onlyOnline, 5, new \DateTimeImmutable('2026-12-01'), 'na_miste')['id']);
        self::assertNull(Pricing::pickTier([], 5, new \DateTimeImmutable()));
    }

    public function testAgeByBirthYear(): void
    {
        // Narozen 31. 12. 2012 → v roce 2027 má podle ročníku 15 let (i když v lednu je mu 14).
        self::assertSame(15, Pricing::ageByYear('2012-12-31', 2027));
        self::assertSame(60, Pricing::ageByYear('1967-01-01', 2027));
        self::assertSame(14, Pricing::exactAge('2012-12-31', new \DateTimeImmutable('2027-01-24')));
    }

    public function testChildAndSeniorDiscount(): void
    {
        $tier = ['amount' => 17000];
        self::assertSame(8500, Pricing::quote($tier, 15, $this->ageRules(), null, 0)['final']);
        self::assertSame(17000, Pricing::quote($tier, 16, $this->ageRules(), null, 0)['final']);
        self::assertSame(17000, Pricing::quote($tier, 59, $this->ageRules(), null, 0)['final']);
        self::assertSame(8500, Pricing::quote($tier, 60, $this->ageRules(), null, 0)['final']);
    }

    public function testDiscountsDoNotStackHigherWins(): void
    {
        $tier = ['amount' => 17000];
        $member = ['percent' => 50, 'fixed_amount' => null];
        $partner = ['percent' => 100, 'fixed_amount' => null];
        $q = Pricing::quote($tier, 10, $this->ageRules(), $member, 0);
        self::assertSame(8500, $q['final'], '50 % věk + 50 % člen = jen 50 %');
        $q = Pricing::quote($tier, 10, $this->ageRules(), $partner, 0);
        self::assertSame(0, $q['final']);
        self::assertSame('kupon', $q['discount_source']);
        self::assertSame(0, $q['age_discount']);
    }

    public function testDiscountNotOnProductsByDefault(): void
    {
        $tier = ['amount' => 17000];
        $member = ['percent' => 50, 'fixed_amount' => null];
        self::assertSame(8500 + 9000, Pricing::quote($tier, 30, [], $member, 9000)['final']);
        self::assertSame(13000, Pricing::quote($tier, 30, [], $member, 9000, true)['final']);
    }

    public function testFixedCouponCannotGoNegative(): void
    {
        self::assertSame(0, Pricing::quote(['amount' => 17000], 30, [], ['percent' => null, 'fixed_amount' => 50000], 0)['final']);
    }

    public function testPercentRoundsToWholeCrowns(): void
    {
        self::assertSame(8500, Pricing::percentOf(16900, 50)); // 84,50 → 85 Kč
    }
}
