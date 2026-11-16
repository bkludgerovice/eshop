<?php
declare(strict_types=1);

namespace Bkl\Domain;

use Bkl\Util;

/** QR Platba (formát SPD 1.0 České bankovní asociace). */
final class Spd
{
    public static function payload(string $iban, int $amountMinor, string $currency, string $vs, string $message, ?string $dueDate = null): string
    {
        $msg = mb_substr(str_replace('*', '', Util::ascii($message)), 0, 60);
        $parts = [
            'SPD*1.0',
            'ACC:' . strtoupper(str_replace(' ', '', $iban)),
            'AM:' . number_format($amountMinor / 100, 2, '.', ''),
            'CC:' . $currency,
            'X-VS:' . $vs,
        ];
        if ($dueDate) {
            $parts[] = 'DT:' . str_replace('-', '', $dueDate);
        }
        $parts[] = 'MSG:' . $msg;
        return implode('*', $parts);
    }

    /** PNG obrázek QR kódu (pro e-mail). */
    public static function png(string $data, int $scale = 6): string
    {
        $opts = new \chillerlan\QRCode\QROptions([
            'outputType' => \chillerlan\QRCode\Output\QROutputInterface::GDIMAGE_PNG,
            'scale' => $scale,
            'outputBase64' => false,
            'eccLevel' => \chillerlan\QRCode\Common\EccLevel::M,
            'addQuietzone' => true,
            'quietzoneSize' => 2,
        ]);
        return (string) (new \chillerlan\QRCode\QRCode($opts))->render($data);
    }

    /** SVG QR kódu (pro web). */
    public static function svg(string $data): string
    {
        $opts = new \chillerlan\QRCode\QROptions([
            'outputType' => \chillerlan\QRCode\Output\QROutputInterface::MARKUP_SVG,
            'outputBase64' => false,
            'eccLevel' => \chillerlan\QRCode\Common\EccLevel::M,
            'addQuietzone' => true,
            'quietzoneSize' => 2,
            'svgAddXmlHeader' => false,
            'drawLightModules' => false,
        ]);
        return (string) (new \chillerlan\QRCode\QRCode($opts))->render($data);
    }
}
