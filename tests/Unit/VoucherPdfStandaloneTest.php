<?php

use Illuminate\Container\Container;
use Vant\Facturas\Pdf\QrImage;
use Vant\Facturas\Pdf\Theme;
use Vant\Facturas\Pdf\VoucherPdf;
use Vant\Facturas\Tests\Support\PdfReader;
use Vant\Facturas\Tests\Support\VoucherPdfFixtures as Fixtures;

/* Outside Laravel: no view factory, the template is included as plain PHP. */

beforeEach(function () {
    $this->container = Container::getInstance();
    Container::setInstance(new Container);
});

afterEach(function () {
    Container::setInstance($this->container);
});

it('renders a voucher without Laravel', function () {
    $doc = Fixtures::classBDocument();
    $html = VoucherPdf::html($doc);
    expect($html)->toContain('C.A.E. N° 74123456789012');
    expect($html)->toContain('Fecha Vto.: 15/09/2026');
    $bytes = VoucherPdf::render($doc);
    expect(substr($bytes, 0, 4))->toBe('%PDF');
    [$page] = PdfReader::read($bytes);
    expect(PdfReader::hasText($page, 'Importe total'))->toBeTrue();
    expect(PdfReader::hasText($page, 'IVA Contenido: $ 212,30'))->toBeTrue();
});

it('draws the QR as one vector path inside a two-module quiet zone', function () {
    $url = Fixtures::classBDocument()['qr'];
    ['size' => $size, 'd' => $d] = QrImage::qrPath($url);
    expect(($size - 17) % 4)->toBe(0);
    expect($d)->toStartWith('M0 0h1v1h-1z');
    $svg = QrImage::svg($url);
    $box = $size + 4;
    expect($svg)->toContain("viewBox=\"0 0 {$box} {$box}\"");
    expect($svg)->toContain('transform="translate(2 2)"');
    expect($svg)->toContain('width="'.Theme::QR_SIZE.'pt"');
    expect(QrImage::dataUri($url))->toStartWith('data:image/svg+xml;base64,');
});

it('resolves a theme, keeping the defaults for blank values', function () {
    expect(Theme::resolveTheme(['accentColor' => '#C00000', 'textColor' => ' ']))->toBe([
        ...Theme::DEFAULT_THEME,
        'accentColor' => '#C00000',
    ]);
    expect(Theme::ISSUER_BOX_MIN_HEIGHT)->toEqualWithDelta(85.04, 0.01);
    expect(Theme::RIGHT_COLUMN_WIDTH)->toEqualWithDelta(215.64, 0.001);
});
