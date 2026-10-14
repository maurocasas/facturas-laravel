<?php

namespace Vant\Facturas\Pdf;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Vant\Facturas\Errors\ArcaConfigurationError;

/**
 * Q1 and Q2: the ARCA QR, drawn as vectors so it stays sharp when printed.
 * The template wraps it in a link to the same URL so it can also be opened
 * from the PDF.
 *
 * Port of `packages/pdf/src/qr.tsx`, on chillerlan/php-qrcode.
 */
final class QrImage
{
    /** The light modules around the code, in modules. */
    public const QUIET_ZONE = 2;

    /**
     * The dark modules of a QR as one SVG path, one unit per module.
     *
     * @return array{size: int, d: string}
     */
    public static function qrPath(string $text): array
    {
        if (! class_exists(QRCode::class)) {
            throw new ArcaConfigurationError(
                'The printed voucher QR needs chillerlan/php-qrcode: run `composer require chillerlan/php-qrcode`.'
            );
        }
        $options = new QROptions([
            'eccLevel' => EccLevel::M,
            'addQuietzone' => false,
        ]);
        $matrix = (new QRCode($options))->addByteSegment($text)->getQRMatrix();
        $size = $matrix->getSize();
        $d = '';
        for ($row = 0; $row < $size; $row++) {
            for ($column = 0; $column < $size; $column++) {
                if ($matrix->check($column, $row)) {
                    $d .= "M{$column} {$row}h1v1h-1z";
                }
            }
        }

        return ['size' => $size, 'd' => $d];
    }

    /** The QR as an SVG document, `QR_SIZE` points square, with a two-module quiet zone. */
    public static function svg(string $url): string
    {
        ['size' => $size, 'd' => $d] = self::qrPath($url);
        $quiet = self::QUIET_ZONE;
        $box = $size + $quiet * 2;
        $points = Theme::QR_SIZE;

        return '<?xml version="1.0" encoding="UTF-8"?>'
            ."<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"{$points}pt\" height=\"{$points}pt\" viewBox=\"0 0 {$box} {$box}\">"
            ."<path d=\"{$d}\" fill=\"#000000\" transform=\"translate({$quiet} {$quiet})\"/>"
            .'</svg>';
    }

    /** The QR SVG as a `data:` URI, for an `<img>`. */
    public static function dataUri(string $url): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(self::svg($url));
    }
}
