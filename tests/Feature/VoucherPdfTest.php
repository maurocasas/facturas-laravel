<?php

use Facturas\Pdf\Format;
use Facturas\Pdf\Theme;
use Facturas\Pdf\VoucherPdf;
use Facturas\Tests\Support\PdfReader;
use Facturas\Tests\Support\VoucherPdfFixtures as Fixtures;

/*
 * Port of packages/pdf/src/voucher.test.tsx. Each rendered voucher is read
 * back and checked rule by rule, with the ids of
 * `.github/PRINTED_VOUCHER_SOURCES.md`. Positions are in points from the top
 * left corner of an A4 page (595 × 842).
 */

function renderVoucher(array $doc, array $options = ['notes' => 'Garantía de 6 meses.']): array
{
    $bytes = VoucherPdf::render($doc, $options);
    expect(substr($bytes, 0, 4))->toBe('%PDF');

    return PdfReader::read($bytes);
}

/** The issuer box spans the top of the page: the first 3 cm plus the page margin. */
const PDF_TOP_ZONE = 140;

function expectApartadoB(array $page, array $doc): void
{
    $middle = $page['width'] / 2;
    // L4: the letter, highlighted, at the top centre, with its code below.
    $letter = PdfReader::findExact($page, $doc['voucherClass']);
    expect($letter['top'])->toBeLessThan(PDF_TOP_ZONE);
    expect(abs($letter['x'] + $letter['fontSize'] * 0.36 - $middle))->toBeLessThan(10);
    expect($letter['fontSize'])->toBeGreaterThanOrEqual(24);
    $code = PdfReader::find($page, 'Código Nº '.$doc['code']);
    expect($code['top'])->toBeGreaterThan($letter['top']);
    expect(abs($code['x'] + 23 - $middle))->toBeLessThan(15);
    // L1: issuer name, address and VAT legend, top left.
    foreach ([
        $doc['issuer']['legalName'],
        $doc['issuer']['address'],
        Format::formatConditionLegend($doc['issuer']['conditionLegend']),
    ] as $fragment) {
        $text = PdfReader::find($page, $fragment);
        expect($text['x'])->toBeLessThan($middle - 40);
        expect($text['top'])->toBeLessThan(PDF_TOP_ZONE);
    }
    // L2: number, date, CUIT, ingresos brutos and start of activities, top right.
    $fragments = [
        'Nº '.$doc['number'],
        'Fecha de emisión:',
        'CUIT: 20-12345678-9',
        'Ingresos Brutos: '.$doc['issuer']['grossIncome'],
    ];
    if (! empty($doc['issuer']['activitiesStartDate'])) {
        $fragments[] = 'Inicio de actividades:';
    }
    foreach ($fragments as $fragment) {
        $text = PdfReader::find($page, $fragment);
        expect($text['x'])->toBeGreaterThan($middle + 30);
        expect($text['top'])->toBeLessThan(PDF_TOP_ZONE);
    }
}

function expectFiscalFooter(array $page, array $doc): void
{
    $middle = $page['width'] / 2;
    $bottom = $page['height'] * 0.82;
    // L9 and C8: the CAE, bottom right.
    $cae = PdfReader::find($page, 'C.A.E. N° '.$doc['authorization']['code']);
    expect($cae['x'])->toBeGreaterThan($middle);
    expect($cae['top'])->toBeGreaterThan($bottom);
    // L9 and C9: its due date, bottom right, at 12 pt or more.
    $dueDate = PdfReader::find($page, 'Fecha Vto.: 15/09/2026');
    expect($dueDate['x'])->toBeGreaterThan($middle);
    expect($dueDate['fontSize'])->toBeGreaterThanOrEqual(12);
    // Q1 and Q2: the QR, bottom right, carrying the voucher's ARCA URL.
    expect($page['links'])->toHaveCount(1);
    expect($page['links'][0]['url'])->toBe($doc['qr']);
    expect($page['links'][0]['x'])->toBeGreaterThan($middle);
    expect($page['links'][0]['top'])->toBeGreaterThan($bottom - 60);
}

/** @return list<array{text: string, top: float}> */
function runsStartingWith(array $pages, string $prefix): array
{
    $runs = [];
    foreach ($pages as $page) {
        foreach ($page['texts'] as $text) {
            if (str_starts_with($text['text'], $prefix)) {
                $runs[] = $text;
            }
        }
    }

    return $runs;
}

/** A class A document whose tax breakdown is too long for one block. */
function longBreakdownDocument(): array
{
    $doc = Fixtures::classADocument();
    $tax = $doc['totals']['otherTaxes'][0];
    $otherTaxes = [];
    for ($index = 0; $index < 15; $index++) {
        $otherTaxes[] = [
            ...$tax,
            'id' => 100 + $index,
            'description' => 'Percepción de Ingresos Brutos Provincia de Buenos Aires Impuesto y contribución municipal por servicios generales '.($index + 1),
        ];
    }
    $doc['totals']['otherTaxes'] = $otherTaxes;

    return $doc;
}

describe('VoucherPdf', function () {
    it('lays out a class B consumer invoice as Apartado B places it', function () {
        $doc = Fixtures::classBDocument();
        [$page] = renderVoucher($doc);
        expectApartadoB($page, $doc);
        expectFiscalFooter($page, $doc);
        // The receiver's second column starts where the issuer box's right column does.
        expect(PdfReader::find($page, 'Condición de venta:')['x'])
            ->toEqualWithDelta(PdfReader::find($page, 'Fecha de emisión:')['x'], 0.5);
        // C15: the receiver legend; L6 and C12: sale conditions and remitos.
        expect(PdfReader::hasText($page, 'A Consumidor Final'))->toBeTrue();
        expect(PdfReader::hasText($page, 'Condición de venta: Contado'))->toBeTrue();
        expect(PdfReader::hasText($page, 'Remitos: 0003-00000012'))->toBeTrue();
        // C19 to C21: description, quantity, unit and total prices with VAT.
        expect(PdfReader::hasText($page, 'Taladro percutor 13 mm'))->toBeTrue();
        expect(PdfReader::hasText($page, '605,00'))->toBeTrue();
        expect(PdfReader::hasText($page, '1.210,00'))->toBeTrue();
        // L8 and G2: the transparency block, bottom left, with both amounts.
        $title = PdfReader::find($page, 'Régimen de Transparencia Fiscal al Consumidor (Ley 27.743)');
        expect($title['x'])->toBeLessThan($page['width'] / 2);
        expect($title['top'])->toBeGreaterThan($page['height'] * 0.82);
        expect(PdfReader::hasText($page, 'IVA Contenido: $ 212,30'))->toBeTrue();
        expect(PdfReader::hasText($page, 'Otros Impuestos Nacionales Indirectos: $ 0,00'))->toBeTrue();
        // Class B never breaks out VAT by rate.
        expect(PdfReader::hasText($page, 'IVA 21%'))->toBeFalse();
        expect(PdfReader::hasText($page, 'Importe total'))->toBeTrue();
        // The title, in sentence case, top right.
        expect(PdfReader::findExact($page, 'Factura')['x'])->toBeGreaterThan($page['width'] / 2);
        expect(PdfReader::hasText($page, 'FACTURA'))->toBeFalse();
    });

    it('breaks VAT out by rate on class A, with its legends and no transparency block', function () {
        $doc = Fixtures::classADocument();
        [$page] = renderVoucher($doc);
        expectApartadoB($page, $doc);
        expectFiscalFooter($page, $doc);
        // C13, C16: the receiver's name, document, address and legend.
        foreach (['Juan Pérez', 'CUIT: 20-11111111-2 · Responsable Monotributo', 'Calle 1, Rosario'] as $fragment) {
            expect(PdfReader::hasText($page, $fragment))->toBeTrue();
        }
        // C25 and L7: rate and tax per rate, after the lines.
        $rate = PdfReader::find($page, 'IVA 21%');
        expect($rate['top'])->toBeGreaterThan(PdfReader::find($page, 'Repuestos')['top']);
        expect(PdfReader::hasText($page, 'IVA 10,5%'))->toBeTrue();
        expect(PdfReader::hasText($page, 'Importe neto gravado'))->toBeTrue();
        expect(PdfReader::hasText($page, 'IIBB'))->toBeTrue();
        // G1 and G3. The legend wraps, so its lines are read joined.
        expect(PdfReader::allText($page))->toContain('El crédito fiscal discriminado en el presente comprobante');
        expect(preg_replace('/\s+/', ' ', PdfReader::allText($page)))->toContain(Fixtures::MONOTRIBUTO_CREDIT_LEGEND);
        expect(PdfReader::hasText($page, 'Observaciones de ARCA: 10217'))->toBeTrue();
        expect(PdfReader::hasText($page, 'Régimen de Transparencia Fiscal'))->toBeFalse();
    });

    it('centres the letter in its box whatever the bold font', function () {
        // The box starts below the page and column padding and its 0.6 pt outline.
        $boxCentre = 32 + 14 + 0.6 + 20;
        // Cap heights of the standard fonts, in thousandths of the size.
        foreach ([['Helvetica-Bold', 718], ['Times-Bold', 676]] as [$boldFontFamily, $capHeight]) {
            [$page] = renderVoucher(Fixtures::classBDocument(), ['theme' => ['boldFontFamily' => $boldFontFamily]]);
            $letter = PdfReader::findExact($page, 'B');
            $capCentre = $letter['top'] - ($letter['fontSize'] * $capHeight) / 2000;
            expect(abs($capCentre - $boxCentre))->toBeLessThan(0.5);
        }
    });

    it('drops the line rate column on class A when every line has the same rate', function () {
        $doc = Fixtures::classADocument();
        $single = $doc;
        $single['lines'] = array_map(fn (array $line) => [...$line, 'vatRate' => 21], $doc['lines']);
        [$page] = renderVoucher($single);
        expect(array_filter($page['texts'], fn (array $text) => trim($text['text']) === 'IVA'))->toBeEmpty();
        [$mixed] = renderVoucher($doc);
        expect(array_filter($mixed['texts'], fn (array $text) => trim($text['text']) === 'IVA'))->not->toBeEmpty();
    });

    it('prints a global discount as one row before the net, net on class A and with VAT on class B', function () {
        foreach ([
            ['A', '100,00', '-$ 12,00', 'Importe neto gravado', '$ 128,00'],
            ['B', '121,00', '-$ 12,10', 'Subtotal', '$ 108,90'],
        ] as [$voucherClass, $line, $discount, $after, $net]) {
            [$page] = renderVoucher(Fixtures::globalDiscountDocument($voucherClass));
            // The lines keep their amounts before the discount.
            expect(PdfReader::hasText($page, $line))->toBeTrue();
            $label = PdfReader::find($page, 'Descuento global');
            expect(abs(PdfReader::find($page, $discount)['top'] - $label['top']))->toBeLessThan(2);
            // The next row is the net, or the subtotal, after the discount.
            $amount = PdfReader::find($page, $net);
            expect($amount['top'])->toBeGreaterThan($label['top']);
            expect($amount['top'] - $label['top'])->toBeLessThan(20);
            expect(array_filter(
                $page['texts'],
                fn (array $text) => trim($text['text']) === $after && abs($text['top'] - $amount['top']) < 2
            ))->not->toBeEmpty();
        }
        [$plain] = renderVoucher(Fixtures::classBDocument());
        expect(PdfReader::hasText($plain, 'Descuento global'))->toBeFalse();
    });

    it('renders class C without VAT, transparency block or an exempt start of activities', function () {
        $doc = Fixtures::classCDocument();
        [$page] = renderVoucher($doc);
        expectApartadoB($page, $doc);
        expectFiscalFooter($page, $doc);
        expect(PdfReader::hasText($page, 'Responsable Monotributo'))->toBeTrue();
        expect(PdfReader::hasText($page, 'DNI: 30111222'))->toBeTrue();
        expect(PdfReader::hasText($page, 'Inicio de actividades'))->toBeFalse();
        expect(PdfReader::hasText($page, 'IVA '))->toBeFalse();
        expect(PdfReader::hasText($page, 'Régimen de Transparencia Fiscal'))->toBeFalse();
    });

    it('repeats the issuer box on every sheet and keeps the CAE on the last one', function () {
        $doc = Fixtures::longDocument();
        $pages = renderVoucher($doc);
        expect(count($pages))->toBeGreaterThan(1);
        foreach ($pages as $page) {
            expectApartadoB($page, $doc);
        }
        $last = $pages[count($pages) - 1];
        expectFiscalFooter($last, $doc);
        expect(PdfReader::hasText($last, 'Hoja '.count($pages).' de '.count($pages)))->toBeTrue();
        foreach (array_slice($pages, 0, -1) as $page) {
            expect(PdfReader::hasText($page, 'C.A.E.'))->toBeFalse();
        }
        expect(runsStartingWith($pages, 'Artículo '))->toHaveCount(60);
    });

    it('takes its slots, but never in a fiscal zone', function () {
        $doc = Fixtures::classBDocument();
        [$page] = renderVoucher($doc, [
            'theme' => ['accentColor' => '#C00000', 'fontFamily' => 'Times-Roman'],
            'brand' => 'TELMO',
            'issuerDetails' => 'Tel. 11 4321-5678',
            'receiverDetails' => 'Cliente Nº 1042',
            'aside' => 'Saldo actual $ 196.773,50',
            'notes' => 'Cambios dentro de los 10 días.',
        ]);
        expectApartadoB($page, $doc);
        expectFiscalFooter($page, $doc);
        $middle = $page['width'] / 2;
        // Brand and issuer details: in the issuer box, around the issuer's data.
        $brand = PdfReader::find($page, 'TELMO');
        expect($brand['x'])->toBeLessThan($middle);
        expect($brand['top'])->toBeLessThan(PdfReader::find($page, $doc['issuer']['legalName'])['top']);
        $issuerDetails = PdfReader::find($page, 'Tel. 11 4321-5678');
        expect($issuerDetails['x'])->toBeLessThan($middle);
        expect($issuerDetails['top'])->toBeGreaterThan(
            PdfReader::find($page, Format::formatConditionLegend($doc['issuer']['conditionLegend']))['top']
        );
        expect($issuerDetails['top'])->toBeLessThan(PdfReader::find($page, 'Receptor')['top']);
        // Receiver details: after the receiver's data, in its column.
        $receiverDetails = PdfReader::find($page, 'Cliente Nº 1042');
        expect($receiverDetails['x'])->toBeLessThan($middle);
        expect($receiverDetails['top'])->toBeGreaterThan(PdfReader::find($page, 'A Consumidor Final')['top']);
        // Aside: left of the totals, never in their column.
        $aside = PdfReader::find($page, 'Saldo actual $ 196.773,50');
        $total = PdfReader::find($page, 'Importe total');
        expect($aside['x'] + 150)->toBeLessThan($total['x']);
        expect($aside['top'])->toBeGreaterThan(PdfReader::find($page, 'Mechas para metal')['top']);
        expect($aside['top'])->toBeLessThan($total['top']);
        // Notes: after the receiver, before the lines, as Stripe's memo.
        $notes = PdfReader::find($page, 'Cambios dentro de los 10 días.');
        expect($notes['top'])->toBeGreaterThan(PdfReader::find($page, 'A Consumidor Final')['top']);
        expect($notes['top'])->toBeLessThan(PdfReader::find($page, 'Descripción')['top']);
    });

    it('keeps the totals with the CAE wherever the sheet breaks', function () {
        $doc = Fixtures::longDocument();
        // Line counts around the first sheet's edge: the closing block fits, then
        // must move, then the lines themselves overflow.
        foreach ([20, 22, 24, 26, 28, 30, 32, 34, 36, 38, 40] as $count) {
            $pages = renderVoucher([...$doc, 'lines' => array_slice($doc['lines'], 0, $count)], []);
            $last = $pages[count($pages) - 1];
            expect(PdfReader::hasText($last, 'Importe total'))->toBeTrue("{$count} lines");
            expectFiscalFooter($last, $doc);
            foreach (array_slice($pages, 0, -1) as $page) {
                expect(PdfReader::hasText($page, 'C.A.E.'))->toBeFalse("{$count} lines");
            }
            expect(runsStartingWith($pages, 'Artículo '))->toHaveCount($count);
        }
    });

    it('lets long notes break across sheets, all of them, clear of the CAE', function () {
        $doc = Fixtures::classBDocument();
        $clauses = [];
        for ($index = 0; $index < 70; $index++) {
            $clauses[] = 'Cláusula '.($index + 1).': condiciones generales de venta.';
        }
        $pages = renderVoucher($doc, ['notes' => implode("\n", $clauses)]);
        expect(count($pages))->toBeGreaterThan(1);
        expect(runsStartingWith($pages, 'Cláusula '))->toHaveCount(70);
        $last = $pages[count($pages) - 1];
        expectFiscalFooter($last, $doc);
        foreach (runsStartingWith([$last], 'Cláusula ') as $text) {
            expect($text['top'])->toBeLessThan(PdfReader::find($last, 'Importe total')['top']);
        }
    });

    it('breaks a token wider than the description column', function () {
        $doc = Fixtures::classBDocument();
        $token = 'https://example.com/'.str_repeat('x', 78);
        $doc['lines'][0]['description'] = $token;
        [$page] = renderVoucher($doc);
        $runs = array_filter($page['texts'], fn (array $text) => str_contains($text['text'], 'xxxx'));
        expect(count(array_unique(array_map(fn (array $run) => (string) $run['top'], $runs))))->toBeGreaterThan(1);
        // Nothing of it runs into the quantity column.
        foreach ($runs as $run) {
            expect($run['x'])->toBeLessThan(PdfReader::find($page, 'Cant.')['x']);
        }
    });

    it('wraps a long tax name instead of running it into its amount', function () {
        $doc = Fixtures::classADocument();
        $name = 'Percepción de Ingresos Brutos Provincia de Buenos Aires';
        $doc['totals']['otherTaxes'] = array_map(fn (array $tax) => [...$tax, 'description' => $name], $doc['totals']['otherTaxes']);
        [$page] = renderVoucher($doc);
        // The name breaks before it reaches the amount's column: its first line
        // does not hold the whole name.
        $first = PdfReader::find($page, 'Percepción de Ingresos');
        expect($first['text'])->not->toContain('Aires');
        expect($first['top'])->toBe(PdfReader::find($page, '$ 3,00')['top']);
        expect(PdfReader::hasText($page, 'Aires'))->toBeTrue();
    });

    it('lets a long tax breakdown continue, with the total kept by the CAE', function () {
        $doc = longBreakdownDocument();
        expect(VoucherPdf::totalsBreak($doc))->toBeTrue();
        expect(VoucherPdf::totalsBreak(Fixtures::classADocument()))->toBeFalse();
        $pages = renderVoucher($doc, []);
        expect(count($pages))->toBeGreaterThan(1);
        expect(runsStartingWith($pages, 'Percepción de Ingresos'))->toHaveCount(15);
        // No sheet squeezes its lines.
        foreach ($pages as $page) {
            $tops = array_values(array_unique(array_map(
                fn (array $text) => round($text['top'] * 10) / 10,
                array_filter($page['texts'], fn (array $text) => $text['x'] > $page['width'] / 2)
            )));
            sort($tops);
            $gaps = [];
            for ($index = 1; $index < count($tops); $index++) {
                $gaps[] = $tops[$index] - $tops[$index - 1];
            }
            expect(min($gaps))->toBeGreaterThan(8);
        }
        $last = $pages[count($pages) - 1];
        expect(PdfReader::hasText($last, 'Importe total'))->toBeTrue();
        expectFiscalFooter($last, $doc);
    });

    it('keeps a short aside with the total when the tax breakdown breaks', function () {
        $doc = longBreakdownDocument();
        $pages = renderVoucher($doc, ['aside' => ['Pagado: $ 100,00', 'Saldo actual: $ 68,20']]);
        expect(count($pages))->toBeGreaterThan(1);
        $last = $pages[count($pages) - 1];
        expect(PdfReader::hasText($last, 'Importe total'))->toBeTrue();
        expect(PdfReader::hasText($last, 'Pagado: $ 100,00'))->toBeTrue();
        expect(PdfReader::hasText($last, 'Saldo actual: $ 68,20'))->toBeTrue();
        expectFiscalFooter($last, $doc);
        $aside = PdfReader::find($last, 'Pagado: $ 100,00');
        $total = PdfReader::find($last, 'Importe total');
        expect($aside['x'])->toBeLessThan($total['x']);
        expect(abs($aside['top'] - $total['top']))->toBeLessThan(15);
    });

    it('lets a row taller than a sheet continue on the next one', function () {
        $doc = Fixtures::classADocument();
        $paragraph = str_repeat('texto de la descripción ', 30)."\n";
        $doc['lines'][0]['description'] = str_repeat($paragraph, 6).'FIN DE LA DESCRIPCIÓN';
        $pages = renderVoucher($doc, []);
        expect(array_filter($pages, fn (array $page) => PdfReader::hasText($page, 'FIN DE LA DESCRIPCIÓN')))->not->toBeEmpty();
        expect(runsStartingWith($pages, 'texto de la descripción'))->not->toBeEmpty();
        $last = $pages[count($pages) - 1];
        expectFiscalFooter($last, $doc);
    });

    it('prints a logo in the brand slot', function () {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $html = VoucherPdf::html(Fixtures::classBDocument(), ['logo' => $png]);
        expect($html)->toContain('<img src="data:image/png;base64,');
        expect(substr(VoucherPdf::render(Fixtures::classBDocument(), ['logo' => $png]), 0, 4))->toBe('%PDF');
    });

    it('sets the document title and author', function () {
        $bytes = VoucherPdf::render(Fixtures::classBDocument());
        $utf16 = fn (string $text) => mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');
        expect($bytes)->toContain($utf16('Factura B 00003-00000041'));
        expect($bytes)->toContain($utf16('Ferretería del Sur SRL'));
        expect($bytes)->toContain($utf16('facturas'));
    });

    describe('refuses a wrong composition', function () {
        it('before rendering', function () {
            expect(fn () => VoucherPdf::render(Fixtures::classBDocument(), ['footer' => 'C.A.E. N° 00000000000000']))
                ->toThrow(InvalidArgumentException::class, 'only takes theme, logo, notes, brand, issuerDetails');
            expect(fn () => VoucherPdf::render(Fixtures::classBDocument(), ['logo' => 'https://example.com/logo.png', 'brand' => 'TELMO']))
                ->toThrow(InvalidArgumentException::class, 'takes one <VoucherBrand>');
            expect(fn () => VoucherPdf::render(['brand' => 'TELMO']))
                ->toThrow(InvalidArgumentException::class, 'renders a voucher document');
            expect(fn () => VoucherPdf::render(Fixtures::classBDocument(), ['aside' => ['html' => 1]]))
                ->toThrow(InvalidArgumentException::class, 'option aside must be');
        });
    });
});

describe('VoucherPdf::html()', function () {
    it('renders the fiscal data through the facturas::pdf.voucher view', function () {
        expect(view()->exists(VoucherPdf::VIEW))->toBeTrue();
        $doc = Fixtures::classADocument();
        $html = VoucherPdf::html($doc, ['notes' => "Garantía de 6 meses.\nCambios dentro de los 10 días."]);
        foreach ([
            '<title>Factura A 00003-00000041</title>',
            'Ferretería del Sur SRL',
            'IVA Responsable Inscripto',
            'Código Nº 001',
            'Nº 00003-00000041',
            '20-12345678-9',
            'Juan Pérez',
            'Responsable Monotributo',
            'Cuenta corriente',
            'Servicio de instalación',
            'IVA 21%',
            'IVA 10,5%',
            'Importe neto gravado',
            '$ 168,20',
            Fixtures::MONOTRIBUTO_CREDIT_LEGEND,
            'Observaciones de ARCA: 10217',
            'C.A.E. N° 74123456789012',
            'Fecha Vto.: 15/09/2026',
            '<div>Garantía de 6 meses.</div><div>Cambios dentro de los 10 días.</div>',
            'href="'.htmlspecialchars($doc['qr']).'"',
            'data:image/svg+xml;base64,',
        ] as $fragment) {
            expect($html)->toContain($fragment);
        }
        // L9: the CAE due date at 12 pt or more.
        expect(Theme::CAE_DUE_DATE_FONT_SIZE)->toBeGreaterThanOrEqual(12);
        expect($html)->toMatch('/\.cae-due-date \{[^}]*font-size: 12pt/');
        expect($html)->not->toContain('Régimen de Transparencia Fiscal');
    });

    it('escapes the document text', function () {
        $doc = Fixtures::classBDocument();
        $doc['lines'][0]['description'] = 'Taladro <script>x()</script>';
        $html = VoucherPdf::html($doc, ['notes' => '<b>x</b>']);
        expect($html)->not->toContain('<script>x()</script>');
        expect($html)->toContain('Taladro &lt;script&gt;x()&lt;/script&gt;');
        expect($html)->toContain('&lt;b&gt;x&lt;/b&gt;');
    });
});

describe('VoucherPdf with VoucherDocument::buildVoucherDocument()', function () {
    it('renders the model the SDK derives, the same as the fixture', function () {
        $qrInput = [
            'taxId' => '20123456789', 'salesPoint' => 3, 'voucherType' => 6, 'number' => 41, 'date' => '2026-09-05',
            'total' => 123_420, 'cae' => '74123456789012', 'document' => ['type' => 99, 'number' => '0'],
        ];
        $doc = \Facturas\Services\VoucherDocument::buildVoucherDocument([
            'voucher' => [
                'salesPoint' => 3, 'number' => 41, 'voucherType' => 6, 'voucherClass' => 'B', 'date' => '2026-09-05',
                'header' => ['concept' => 1, 'documentType' => 99, 'documentNumber' => '0', 'receiverVatConditionId' => 5, 'currencyId' => 'PES'],
                'cae' => '74123456789012', 'caeExpiry' => '2026-09-15',
                'amounts' => ['computedTotal' => 123_420, 'sentTotal' => 123_420, 'vatAdjustment' => 0],
                'totals' => [
                    'total' => 123_420, 'netTaxed' => 102_190, 'untaxed' => 0, 'exempt' => 0, 'vat' => 21_230, 'otherTaxes' => 0,
                    'vatRates' => [
                        ['id' => 5, 'rate' => 21, 'base' => 100_000, 'amount' => 21_000],
                        ['id' => 4, 'rate' => 10.5, 'base' => 2190, 'amount' => 230],
                    ],
                    'taxes' => [],
                ],
                'qr' => \Facturas\Services\Qr::arcaQrUrl($qrInput),
                'qrPayload' => \Facturas\Services\Qr::arcaQrPayload($qrInput),
            ],
            'items' => [
                ['description' => 'Taladro percutor 13 mm', 'quantity' => 2, 'unitPrice' => '605.00', 'gross' => 121_000, 'vat' => 21],
                ['description' => 'Mechas para metal', 'gross' => 2420, 'vat' => 10.5],
            ],
            'issuer' => [
                'legalName' => 'Ferretería del Sur SRL', 'tradeName' => 'Ferretería del Sur', 'address' => 'Av. Rivadavia 1234, CABA',
                'taxId' => '20123456789', 'condition' => 'responsable_inscripto', 'grossIncome' => '901-123456-7',
                'activitiesStartDate' => '2019-10-01',
            ],
            'saleConditions' => 'Contado',
            'remitos' => ['0003-00000012'],
        ]);
        expect($doc)->toEqual(Fixtures::classBDocument());
        expect(Fixtures::MONOTRIBUTO_CREDIT_LEGEND)->toBe(\Facturas\Services\VoucherDocument::MONOTRIBUTO_CREDIT_LEGEND);
        [$page] = renderVoucher($doc);
        expectApartadoB($page, $doc);
        expectFiscalFooter($page, $doc);
    });
});
