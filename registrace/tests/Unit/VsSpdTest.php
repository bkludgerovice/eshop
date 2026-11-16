<?php
declare(strict_types=1);

namespace Bkl\Tests\Unit;

use Bkl\Domain\FioClient;
use Bkl\Domain\Spd;
use Bkl\Domain\Vs;
use Bkl\Util;
use PHPUnit\Framework\TestCase;

final class VsSpdTest extends TestCase
{
    public function testVsFormat(): void
    {
        self::assertSame('827010001', Vs::make(2027, 1, 1));
        self::assertSame('827129999', Vs::make(2027, 12, 9999));
        self::assertTrue(Vs::isOurs('827010001'));
        self::assertTrue(Vs::isOurs('0827010001'));
        self::assertFalse(Vs::isOurs('2701001'), 'VS plesu nesmí patřit registracím');
        self::assertFalse(Vs::isOurs(''));
        $this->expectException(\OverflowException::class);
        Vs::make(2027, 1, 10000);
    }

    public function testSpdPayload(): void
    {
        $p = Spd::payload('CZ55 2010 0000 0025 0251 8589', 17000, 'CZK', '827010001', 'Zimní běh * Hlučín', '2027-01-10');
        self::assertSame('SPD*1.0*ACC:CZ5520100000002502518589*AM:170.00*CC:CZK*X-VS:827010001*DT:20270110*MSG:Zimni beh  Hlucin', $p);
    }

    public function testQrRenders(): void
    {
        $png = Spd::png('SPD*1.0*ACC:CZ5520100000002502518589*AM:170.00*CC:CZK*X-VS:827010001');
        self::assertStringStartsWith("\x89PNG", $png);
        self::assertStringContainsString('<svg', Spd::svg('BKL:ABC'));
    }

    public function testFioParse(): void
    {
        $json = json_encode(['accountStatement' => ['transactionList' => ['transaction' => [
            ['column22' => ['value' => 26001], 'column0' => ['value' => '2026-11-20+0100'], 'column1' => ['value' => 170.0],
             'column5' => ['value' => '827010001'], 'column2' => ['value' => '123456'], 'column3' => ['value' => '0800'],
             'column10' => ['value' => 'Novák Jan'], 'column16' => ['value' => 'startovne'], 'column14' => ['value' => 'CZK']],
            ['column22' => ['value' => 26002], 'column0' => ['value' => '2026-11-20+0100'], 'column1' => ['value' => -50.5]],
        ]]]]);
        $t = FioClient::parse((string) $json);
        self::assertCount(2, $t);
        self::assertSame('26001', $t[0]['id']);
        self::assertSame(17000, $t[0]['amount']);
        self::assertSame('2026-11-20 00:00:00', $t[0]['date']);
        self::assertSame('123456/0800', $t[0]['account']);
        self::assertSame(-5050, $t[1]['amount']);
    }

    public function testMoney(): void
    {
        self::assertSame("1\u{00a0}234\u{00a0}Kč", Util::money(123400));
        self::assertSame("85,50\u{00a0}Kč", Util::money(8550));
        self::assertSame(17000, Util::parseMoney('170'));
        self::assertSame(17050, Util::parseMoney('170,50 Kč'));
        self::assertNull(Util::parseMoney('abc'));
    }
}
