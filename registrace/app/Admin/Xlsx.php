<?php
declare(strict_types=1);

namespace Bkl\Admin;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/** Vytvoření souboru Excel (.xlsx) z hlavičky a řádků. */
final class Xlsx
{
    /** @param list<string> $header @param iterable<list<scalar|null>> $rows */
    public static function build(array $header, iterable $rows): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'bklx');
        $w = new Writer();
        $w->openToFile($tmp);
        $w->addRow(Row::fromValues($header, (new Style())->setFontBold()));
        foreach ($rows as $r) {
            $w->addRow(Row::fromValues($r));
        }
        $w->close();
        $data = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $data;
    }
}
