<?php

namespace Vant\Facturas\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use DOMElement;
use Illuminate\Container\Container;
use InvalidArgumentException;
use Throwable;
use Vant\Facturas\Errors\ArcaConfigurationError;
use Vant\Facturas\Services\VoucherDocument;

/**
 * The A4 PDF of a voucher, drawn with dompdf. Port of `@facturas/pdf`
 * (`renderVoucherPdf()`, `<Voucher>` and its slots).
 *
 * Pass what `VoucherDocument::buildVoucherDocument()` returned and, as
 * options:
 *
 * - `theme`: fonts and colours, see {@see Theme}. Sizes and placement are
 *   fixed, because the norms set some of them.
 * - `logo`: a PNG or JPEG for the brand slot, top left above the issuer's
 *   name: a URL, a `data:` URI, a file path or its bytes.
 * - `notes`: free text for the notes slot, such as warranty or returns. It goes
 *   after the receiver and before the lines, and may run across sheets.
 * - The other slots of `<Voucher>`, each a fixed place outside the fiscal
 *   zones: `brand` (instead of `logo`), `issuerDetails` (in the issuer box,
 *   after its fiscal data: phone, email, website), `receiverDetails` (after
 *   the receiver's data), `aside` (left of the totals: payments received or
 *   the account balance; keep it short, it moves with the totals and the
 *   CAE). A slot takes a string (plain text, one line per "\n"), a list of
 *   lines, or `['html' => '...']` with trusted HTML.
 * - `dompdfOptions`: options for `Dompdf\Options`, such as `fontDir` and
 *   `fontCache` for a font the theme names.
 *
 * The fiscal blocks are placed by the voucher itself: any other option, or a
 * slot given twice, throws before anything renders.
 */
final class VoucherPdf
{
    /** The view the package service provider registers. */
    public const VIEW = 'facturas::pdf.voucher';

    /** Totals rows taller than this may break across sheets; see `totalsBreak()`. */
    public const UNBREAKABLE_TOTALS_HEIGHT = 250;

    /** A conservative fit for a totals label beside its amount, at 9 pt. */
    private const TOTALS_LABEL_CHARS_PER_LINE = 28;

    private const TOTALS_LINE_HEIGHT = 12.15;

    private const TOTALS_ROW_CHROME = 2.5;

    /**
     * A row stays on one sheet unless its description could outgrow one: an
     * unbreakable row taller than the sheet would be clipped.
     */
    public const UNBREAKABLE_DESCRIPTION = 600;

    /**
     * A longer description is printed a paragraph per row, so the sheet can
     * break between them; a paragraph longer than this, about 40 lines of the
     * column, in rows of at most this many characters.
     */
    private const DESCRIPTION_CHUNK = 2000;

    /** Longer than a description column holds: such a token may break anywhere. */
    private const LONG_TOKEN = 20;

    /** Fixed texts a printed voucher carries, as `VoucherDocument::PRINTED_VOUCHER_TEXT` words them. */
    private const PRINTED_VOUCHER_TEXT = [
        'code' => 'Código Nº',
        'activitiesStart' => 'INICIO DE ACTIVIDADES',
        'cae' => 'C.A.E. N°',
        'caeDueDate' => 'Fecha Vto.:',
        'transparencyTitle' => 'Régimen de Transparencia Fiscal al Consumidor (Ley 27.743)',
        'vatContained' => 'IVA Contenido',
        'otherNationalIndirectTaxes' => 'Otros Impuestos Nacionales Indirectos',
    ];

    private const SLOTS = [
        'brand' => 'VoucherBrand',
        'issuerDetails' => 'VoucherIssuerDetails',
        'receiverDetails' => 'VoucherReceiverDetails',
        'aside' => 'VoucherAside',
        'notes' => 'VoucherNotes',
    ];

    private const OPTIONS = ['theme', 'logo', 'notes', 'brand', 'issuerDetails', 'receiverDetails', 'aside', 'dompdfOptions'];

    private const DOCUMENT_KEYS = [
        'voucherClass', 'code', 'title', 'number', 'issueDate', 'issuer', 'receiver', 'saleConditions',
        'currency', 'lines', 'totals', 'authorization', 'qr', 'legends',
    ];

    /**
     * The PDF bytes of the voucher.
     *
     * @param  array<string, mixed>  $document  What `VoucherDocument::buildVoucherDocument()` returned.
     * @param  array<string, mixed>  $options
     */
    public static function render(array $document, array $options = []): string
    {
        self::assertDompdf();
        $html = self::html($document, $options);
        $theme = Theme::resolveTheme((array) ($options['theme'] ?? []));
        $dompdf = self::dompdf($options, self::needsRemote($options));
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->addInfo('Creator', 'facturas');
        $dompdf->render();
        self::sheetNumbers($dompdf, $theme);

        return (string) $dompdf->output();
    }

    /**
     * The HTML dompdf draws, for a preview or a template of your own.
     *
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $options
     */
    public static function html(array $document, array $options = []): string
    {
        self::assertDocument($document);
        $slots = self::collectSlots($options);
        $theme = Theme::resolveTheme((array) ($options['theme'] ?? []));
        $data = [
            'doc' => $document,
            'title' => Format::formatTitle($document['title']).' '.$document['voucherClass'].' '.$document['number'],
            'qr' => QrImage::dataUri($document['qr']),
            'slots' => $slots,
            'layout' => self::layout($document),
            'headerOnly' => true,
            'css' => Theme::createStyles($theme, 0),
        ];
        $headerHeight = class_exists(Dompdf::class)
            ? self::measureHeader(self::view($data), $options)
            : self::estimateHeader($document, $slots);

        return self::view([
            ...$data,
            'headerOnly' => false,
            'css' => Theme::createStyles($theme, $headerHeight),
        ]);
    }

    /**
     * What the template derives from the document: which optional columns
     * the lines take, the totals rows and whether they may break.
     *
     * @internal
     *
     * @param  array<string, mixed>  $doc
     * @return array{showCode: bool, showDiscount: bool, showRate: bool, lines: list<array{line: array<string, mixed>, description: list<string>}>, totalsRows: list<array{key: string, label: string, amount: int|float}>, breakTotals: bool, text: array<string, string>}
     */
    public static function layout(array $doc): array
    {
        $lines = $doc['lines'];
        $rates = [];
        foreach ($lines as $line) {
            $rates[array_key_exists('vatRate', $line) ? 'r'.json_encode($line['vatRate']) : 'undefined'] = true;
        }

        return [
            'showCode' => self::some($lines, fn (array $line) => isset($line['code'])),
            'showDiscount' => self::some($lines, fn (array $line) => isset($line['discount'])),
            // The rate per line only tells something apart when the lines differ: with
            // one rate, the VAT row of the totals already names it.
            'showRate' => $doc['voucherClass'] === 'A' && count($rates) > 1,
            'lines' => array_map(
                fn (array $line) => ['line' => $line, 'description' => self::descriptionRows((string) $line['description'])],
                array_values($lines)
            ),
            'totalsRows' => self::totalsRows($doc),
            'breakTotals' => self::totalsBreak($doc),
            'text' => self::printedText(),
        ];
    }

    /**
     * C23, C25, L7: the rows above the total, VAT by rate right after the lines on class A.
     *
     * @param  array<string, mixed>  $doc
     * @return list<array{key: string, label: string, amount: int|float}>
     */
    public static function totalsRows(array $doc): array
    {
        $totals = $doc['totals'];
        $classA = $doc['voucherClass'] === 'A';
        $rows = [];
        // The lines are before the discount and the subtotal after it.
        if (($totals['globalDiscount'] ?? 0) > 0) {
            $rows[] = ['key' => 'global-discount', 'label' => 'Descuento global', 'amount' => -$totals['globalDiscount']];
        }
        $rows[] = ['key' => 'subtotal', 'label' => $classA ? 'Importe neto gravado' : 'Subtotal', 'amount' => $totals['subtotal']];
        if ($classA && ($totals['exempt'] ?? 0) > 0) {
            $rows[] = ['key' => 'exempt', 'label' => 'Importe exento', 'amount' => $totals['exempt']];
        }
        if ($classA && ($totals['untaxed'] ?? 0) > 0) {
            $rows[] = ['key' => 'untaxed', 'label' => 'Importe no gravado', 'amount' => $totals['untaxed']];
        }
        foreach ($totals['vatRates'] ?? [] as $row) {
            $rows[] = [
                'key' => 'vat-'.$row['id'],
                'label' => 'IVA '.(isset($row['rate']) ? Format::formatVatRate($row['rate']) : '('.$row['id'].')'),
                'amount' => $row['amount'],
            ];
        }
        // Two provinces' IIBB perceptions share the tribute id.
        foreach (array_values($totals['otherTaxes'] ?? []) as $index => $tax) {
            $rows[] = ['key' => 'tax-'.$index.'-'.$tax['id'], 'label' => $tax['description'], 'amount' => $tax['amount']];
        }
        if (($totals['adjustment'] ?? 0) != 0) {
            $rows[] = ['key' => 'adjustment', 'label' => 'Ajuste', 'amount' => $totals['adjustment']];
        }

        return $rows;
    }

    /**
     * Whether the rows above the total may break across sheets. A usual breakdown
     * stays in one block with the total and the CAE. A long one, many wrapped tax
     * names, would not fit a sheet that way, so its rows flow and only the total
     * stays with the CAE.
     *
     * @param  array<string, mixed>  $doc
     */
    public static function totalsBreak(array $doc): bool
    {
        $height = 0;
        foreach (self::totalsRows($doc) as $row) {
            $height += ceil(mb_strlen($row['label'], 'UTF-8') / self::TOTALS_LABEL_CHARS_PER_LINE) * self::TOTALS_LINE_HEIGHT
                + self::TOTALS_ROW_CHROME;
        }

        return $height > self::UNBREAKABLE_TOTALS_HEIGHT;
    }

    /** @return array<string, string> */
    private static function printedText(): array
    {
        $constant = VoucherDocument::class.'::PRINTED_VOUCHER_TEXT';

        return defined($constant) ? constant($constant) : self::PRINTED_VOUCHER_TEXT;
    }

    /**
     * Descriptions wrap between words, never as "Col-or". Only a token too long
     * for the column, such as a URL, breaks inside so it cannot overrun the
     * quantity and price columns. A description that could outgrow a sheet is
     * split into rows the sheet may break between.
     *
     * @return list<string>
     */
    private static function descriptionRows(string $description): array
    {
        $description = (string) preg_replace_callback(
            '/\S{'.(self::LONG_TOKEN + 1).',}/u',
            // Spaces of no width are not in the standard PDF fonts: the token is cut in
            // pieces the column holds, which dompdf may then break between.
            fn (array $match) => implode(' ', mb_str_split($match[0], self::LONG_TOKEN, 'UTF-8')),
            $description
        );
        if (mb_strlen($description, 'UTF-8') <= self::UNBREAKABLE_DESCRIPTION) {
            return [$description];
        }
        $rows = [];
        foreach (explode("\n", $description) as $paragraph) {
            $current = '';
            foreach (preg_split('/(?<=\s)/u', $paragraph) ?: [] as $word) {
                if ($current !== '' && mb_strlen($current.$word, 'UTF-8') > self::DESCRIPTION_CHUNK) {
                    $rows[] = rtrim($current);
                    $current = '';
                }
                $current .= $word;
            }
            $rows[] = rtrim($current);
        }

        return $rows;
    }

    /**
     * The slots, from the options, as HTML. Mirrors `collectSlots()`: anything
     * that is not a slot, or a slot given twice, throws.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, string|null>
     */
    private static function collectSlots(array $options): array
    {
        foreach (array_keys($options) as $key) {
            if (! in_array($key, self::OPTIONS, true)) {
                throw new InvalidArgumentException(
                    'VoucherPdf only takes '.implode(', ', self::OPTIONS).' as options: the fiscal blocks are placed by the voucher itself.'
                );
            }
        }
        if (isset($options['logo']) && isset($options['brand'])) {
            throw new InvalidArgumentException('VoucherPdf takes one <'.self::SLOTS['brand'].'>: pass logo or brand, not both.');
        }
        $slots = [];
        foreach (array_keys(self::SLOTS) as $slot) {
            $slots[$slot] = self::slotHtml($slot, $options[$slot] ?? null);
        }
        if (isset($options['logo'])) {
            $slots['brand'] = '<img src="'.self::escape(self::logoSource($options['logo'])).'" alt="">';
        }

        return $slots;
    }

    private static function slotHtml(string $slot, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return implode('', array_map(
                fn (string $line) => '<div>'.($line === '' ? '&nbsp;' : self::escape($line)).'</div>',
                explode("\n", str_replace("\r\n", "\n", $value))
            ));
        }
        if (is_array($value) && array_is_list($value)) {
            return implode('', array_map(fn ($line) => self::slotHtml($slot, (string) $line), $value));
        }
        if (is_array($value) && is_string($value['html'] ?? null) && count($value) === 1) {
            return $value['html'];
        }
        throw new InvalidArgumentException(
            "VoucherPdf option {$slot} must be a string, a list of lines or ['html' => string]."
        );
    }

    /** A PNG or JPEG for the brand slot: a URL, a data URI, a file path or its bytes. */
    private static function logoSource(mixed $logo): string
    {
        if (! is_string($logo) || $logo === '') {
            throw new InvalidArgumentException('VoucherPdf option logo must be a URL, a data URI, a file path or the bytes of a PNG or JPEG.');
        }
        if (preg_match('#^(data:|https?://|file://)#i', $logo) === 1) {
            return $logo;
        }
        $bytes = $logo;
        if (! str_contains($logo, "\0") && strlen($logo) < 4096 && @is_file($logo)) {
            $bytes = (string) file_get_contents($logo);
        }
        if (str_starts_with($bytes, "\x89PNG")) {
            return 'data:image/png;base64,'.base64_encode($bytes);
        }
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'data:image/jpeg;base64,'.base64_encode($bytes);
        }
        throw new InvalidArgumentException('VoucherPdf option logo must be a URL, a data URI, a file path or the bytes of a PNG or JPEG.');
    }

    /** @param  array<string, mixed>  $options */
    private static function needsRemote(array $options): bool
    {
        return is_string($options['logo'] ?? null) && preg_match('#^https?://#i', $options['logo']) === 1;
    }

    /** @param  array<string, mixed>  $document */
    private static function assertDocument(array $document): void
    {
        foreach (self::DOCUMENT_KEYS as $key) {
            if (! array_key_exists($key, $document)) {
                throw new InvalidArgumentException(
                    'VoucherPdf renders a voucher document: what VoucherDocument::buildVoucherDocument() returned.'
                );
            }
        }
    }

    private static function assertDompdf(): void
    {
        if (! class_exists(Dompdf::class)) {
            throw new ArcaConfigurationError(
                'The printed voucher PDF needs dompdf/dompdf: run `composer require dompdf/dompdf`.'
            );
        }
    }

    /** @param  array<string, mixed>  $options */
    private static function dompdf(array $options, bool $remote): Dompdf
    {
        $settings = new Options(array_merge(
            ['defaultFont' => 'helvetica', 'isRemoteEnabled' => $remote, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false],
            (array) ($options['dompdfOptions'] ?? [])
        ));

        return new Dompdf($settings);
    }

    /**
     * The issuer box's height: drawn alone at the page's width, as dompdf lays
     * it out, so every sheet leaves it exactly its room.
     *
     * @param  array<string, mixed>  $options
     */
    private static function measureHeader(string $html, array $options): float
    {
        $dompdf = self::dompdf($options, self::needsRemote($options));
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $height = null;
        // dompdf drops its frame tree once rendered: the box is read as it is laid out.
        $dompdf->setCallbacks([[
            'event' => 'end_frame',
            'f' => function ($frame) use (&$height): void {
                $node = $frame->get_node();
                if ($height === null && $node instanceof DOMElement && $node->getAttribute('id') === 'facturas-issuer') {
                    $height = (float) $frame->get_margin_height();
                }
            },
        ]]);
        $dompdf->render();
        if ($height !== null) {
            return $height;
        }

        return Theme::ISSUER_BOX_MIN_HEIGHT;
    }

    /**
     * Without dompdf, for an HTML preview: the issuer box's height from its
     * lines at 9 pt, as its taller column takes it.
     *
     * @param  array<string, mixed>  $doc
     * @param  array<string, string|null>  $slots
     */
    private static function estimateHeader(array $doc, array $slots): float
    {
        $line = 9 * 1.35 + 1;
        $left = ($slots['brand'] !== null ? 50 : 0)
            + (empty($doc['issuer']['tradeName']) ? 0 : 11 * 1.35 + 3)
            + 11 * 1.35 + 2 + $line * 2 + 2
            + ($slots['issuerDetails'] !== null ? 4 + $line * max(1, substr_count($slots['issuerDetails'], '<div')) : 0);
        $right = 18 * 1.1 + 10 * 1.35 + 7 + $line * (empty($doc['issuer']['activitiesStartDate']) ? 3 : 4);
        $inner = max($left, $right, Theme::ISSUER_BOX_MIN_HEIGHT - 2 * Theme::OUTLINE_WIDTH - 2 * Theme::BOX_PADDING);

        return $inner + 2 * Theme::BOX_PADDING + 2 * Theme::OUTLINE_WIDTH;
    }

    /**
     * "Hoja n de m" on every sheet of a voucher that takes more than one.
     *
     * @param  array{fontFamily: string, textColor: string}  $theme
     */
    private static function sheetNumbers(Dompdf $dompdf, array $theme): void
    {
        $canvas = $dompdf->getCanvas();
        $pages = $canvas->get_page_count();
        if ($pages <= 1) {
            return;
        }
        $metrics = $dompdf->getFontMetrics();
        $family = strtolower(trim($theme['fontFamily']));
        $family = match (true) {
            str_starts_with($family, 'helvetica') => 'helvetica',
            str_starts_with($family, 'times') => 'times',
            str_starts_with($family, 'courier') => 'courier',
            default => $theme['fontFamily'],
        };
        $font = $metrics->getFont($family, 'normal') ?? $metrics->getFont('helvetica', 'normal');
        $size = Theme::PAGE_NUMBER_FONT_SIZE;
        // The widest the text gets, so its right edge stays 40 pt from the sheet's.
        $width = $metrics->getTextWidth("Hoja {$pages} de {$pages}", $font, $size);
        $x = Theme::A4_WIDTH - Theme::PAGE_MARGIN - $width;
        $y = Theme::A4_HEIGHT - Theme::PAGE_NUMBER_BOTTOM - $metrics->getFontHeight($font, $size);
        $canvas->page_text($x, $y, 'Hoja {PAGE_NUM} de {PAGE_COUNT}', $font, $size, self::rgb($theme['textColor']));
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function rgb(string $color): array
    {
        $hex = ltrim(trim($color), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (preg_match('/^[0-9a-f]{6}$/i', $hex) !== 1) {
            return [0.0, 0.0, 0.0];
        }

        return [hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255];
    }

    /**
     * Through Laravel's view factory when the package's views are registered,
     * so a published view takes over; otherwise the template is plain PHP and
     * is included directly.
     *
     * @param  array<string, mixed>  $data
     */
    private static function view(array $data): string
    {
        if (class_exists(Container::class)) {
            $container = Container::getInstance();
            if ($container->bound('view')) {
                $factory = $container->make('view');
                if ($factory->exists(self::VIEW)) {
                    return $factory->make(self::VIEW, $data)->render();
                }
            }
        }

        return self::includeTemplate(dirname(__DIR__, 2).'/resources/views/pdf/voucher.blade.php', $data);
    }

    /** @param  array<string, mixed>  $__data */
    private static function includeTemplate(string $__path, array $__data): string
    {
        if (! is_file($__path)) {
            throw new ArcaConfigurationError("The printed voucher template is missing: {$__path}.");
        }
        $level = ob_get_level();
        ob_start();
        try {
            (static function () use ($__path, $__data): void {
                extract($__data, EXTR_SKIP);
                include $__path;
            })();
        } catch (Throwable $error) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $error;
        }

        return (string) ob_get_clean();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private static function some(array $items, callable $predicate): bool
    {
        foreach ($items as $item) {
            if ($predicate($item)) {
                return true;
            }
        }

        return false;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
