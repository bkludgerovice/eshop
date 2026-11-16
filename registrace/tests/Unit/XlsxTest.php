<?php
declare(strict_types=1);

namespace Bkl\Tests\Unit;

use Bkl\Admin\Xlsx;
use PHPUnit\Framework\TestCase;

final class XlsxTest extends TestCase
{
    public function testBuildsReadableXlsx(): void
    {
        set_error_handler(static function (int $no, string $msg): bool {
            if ($no === E_DEPRECATED || $no === E_USER_DEPRECATED) {
                return true;
            }
            throw new \ErrorException($msg, 0, $no);
        });
        try {
            $data = Xlsx::build(['Příjmení', 'Ročník', 'Cena'], [['Nováková', 2014, 85.5], ['Žluťoučký', 1980, 170]]);
        } finally {
            restore_error_handler();
        }
        self::assertStringStartsWith('PK', $data);
        $f = tempnam(sys_get_temp_dir(), 'x') . '.xlsx';
        file_put_contents($f, $data);
        $r = new \OpenSpout\Reader\XLSX\Reader();
        $r->open($f);
        $rows = [];
        foreach ($r->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $r->close();
        @unlink($f);
        self::assertSame(['Příjmení', 'Ročník', 'Cena'], $rows[0]);
        self::assertSame('Žluťoučký', $rows[2][0]);
        self::assertCount(3, $rows);
    }
}
