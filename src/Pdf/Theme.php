<?php

namespace Facturas\Pdf;

/**
 * What a voucher may restyle: fonts and colours. Sizes and placement are
 * fixed, because the norms set them (the CAE due date at 12 pt or more, the
 * Apartado B zones), so a theme cannot shrink or move a fiscal datum.
 *
 * Port of `packages/pdf/src/theme.ts`. A theme is an array with any of:
 * `fontFamily` (a font dompdf knows: a standard PDF font such as `Helvetica`
 * or `Times-Roman`, or one installed in dompdf's font directory),
 * `boldFontFamily` (the bold face of `fontFamily`), `accentColor` (titles, the
 * letter and the total), `textColor` (body text), `mutedColor` (the labels
 * before a value, such as "CUIT:") and `borderColor` (the separators between
 * table rows and totals).
 *
 * @phpstan-type VoucherTheme array{fontFamily?: string, boldFontFamily?: string, accentColor?: string, textColor?: string, mutedColor?: string, borderColor?: string}
 * @phpstan-type ResolvedTheme array{fontFamily: string, boldFontFamily: string, accentColor: string, textColor: string, mutedColor: string, borderColor: string}
 */
final class Theme
{
    public const DEFAULT_THEME = [
        'fontFamily' => 'Helvetica',
        'boldFontFamily' => 'Helvetica-Bold',
        'accentColor' => '#000000',
        'textColor' => '#000000',
        'mutedColor' => '#000000',
        'borderColor' => '#EBEBEB',
    ];

    /** Points per centimetre. */
    private const CM = 72 / 2.54;

    /** RG 1415, Anexo II, B, after b): the issuer data box is at least 7 × 3 cm. */
    public const ISSUER_BOX_MIN_HEIGHT = 3 * self::CM;

    /** L9: the CAE due date is printed at 12 pt (`PRINTED_CAE_DUE_DATE_MIN_FONT_SIZE_PT`). */
    public const CAE_DUE_DATE_FONT_SIZE = 12;

    public const QR_SIZE = 76;

    public const OUTLINE_COLOR = '#999999';

    public const A4_WIDTH = 595.28;

    public const A4_HEIGHT = 841.89;

    public const PAGE_MARGIN = 40;

    public const PAGE_PADDING_TOP = 32;

    public const PAGE_PADDING_BOTTOM = 36;

    public const BOX_PADDING = 14;

    public const LETTER_COLUMN_WIDTH = 84;

    /** The sheet number: 20 pt above the bottom edge, 40 pt from the right one. */
    public const PAGE_NUMBER_BOTTOM = 20;

    public const PAGE_NUMBER_FONT_SIZE = 7.5;

    /** The outline of the issuer box and the letter box. */
    public const OUTLINE_WIDTH = 0.6;

    /**
     * The right column of the issuer box. The receiver section's second column
     * takes the same width, so all the text on the right starts on one vertical.
     */
    public const RIGHT_COLUMN_WIDTH = (self::A4_WIDTH - 2 * self::PAGE_MARGIN - self::LETTER_COLUMN_WIDTH) / 2;

    /** The width the page leaves between its side margins. */
    public const CONTENT_WIDTH = self::A4_WIDTH - 2 * self::PAGE_MARGIN;

    /** The totals' column, right of the aside. */
    public const TOTALS_WIDTH = 240;

    /** The fiscal footer: its separator, its top padding and the QR. */
    public const FOOTER_HEIGHT = 0.5 + 12 + self::QR_SIZE;

    /** The 14 standard PDF fonts, as dompdf names their families. */
    private const STANDARD_FONTS = [
        'helvetica' => ['helvetica', 'normal', 'normal'],
        'helvetica-bold' => ['helvetica', 'bold', 'normal'],
        'helvetica-oblique' => ['helvetica', 'normal', 'italic'],
        'helvetica-boldoblique' => ['helvetica', 'bold', 'italic'],
        'times-roman' => ['times', 'normal', 'normal'],
        'times-bold' => ['times', 'bold', 'normal'],
        'times-italic' => ['times', 'normal', 'italic'],
        'times-bolditalic' => ['times', 'bold', 'italic'],
        'courier' => ['courier', 'normal', 'normal'],
        'courier-bold' => ['courier', 'bold', 'normal'],
        'courier-oblique' => ['courier', 'normal', 'italic'],
        'courier-boldoblique' => ['courier', 'bold', 'italic'],
        'symbol' => ['symbol', 'normal', 'normal'],
        'zapfdingbats' => ['zapfdingbats', 'normal', 'normal'],
    ];

    /**
     * @param  array<string, mixed>  $theme
     * @return array{fontFamily: string, boldFontFamily: string, accentColor: string, textColor: string, mutedColor: string, borderColor: string}
     */
    public static function resolveTheme(array $theme = []): array
    {
        $resolved = self::DEFAULT_THEME;
        foreach (array_keys(self::DEFAULT_THEME) as $key) {
            $value = $theme[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $resolved[$key] = $value;
            }
        }

        return $resolved;
    }

    /**
     * The stylesheet of the voucher for dompdf. `$headerHeight` is the issuer
     * box's height: every sheet keeps it at its top, so the page's top margin
     * leaves room for it.
     *
     * @param  array{fontFamily: string, boldFontFamily: string, accentColor: string, textColor: string, mutedColor: string, borderColor: string}  $theme
     */
    public static function createStyles(array $theme, float $headerHeight): string
    {
        $font = self::cssFont($theme['fontFamily'], false);
        $bold = self::cssFont($theme['boldFontFamily'], true);
        $accent = self::cssValue($theme['accentColor']);
        $text = self::cssValue($theme['textColor']);
        $muted = self::cssValue($theme['mutedColor']);
        $border = self::cssValue($theme['borderColor']);
        $outline = self::OUTLINE_WIDTH.'pt solid '.self::OUTLINE_COLOR;

        $pt = static fn (float|int $value): string => rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.').'pt';

        $topMargin = $pt(self::PAGE_PADDING_TOP + $headerHeight);
        $sideWidth = $pt((self::CONTENT_WIDTH - 2 * self::OUTLINE_WIDTH - self::LETTER_COLUMN_WIDTH) / 2 - 2 * self::BOX_PADDING);
        $boxHeight = $pt(self::ISSUER_BOX_MIN_HEIGHT - 2 * self::OUTLINE_WIDTH - 2 * self::BOX_PADDING);
        $rightColumn = $pt(self::RIGHT_COLUMN_WIDTH - self::BOX_PADDING);
        $headerTop = $pt(-$headerHeight);
        $footerHeight = $pt(self::FOOTER_HEIGHT - 12.5);
        $closingSpace = $pt(self::FOOTER_HEIGHT + 12);
        $qr = $pt(self::QR_SIZE);
        $asideWidth = $pt(self::CONTENT_WIDTH - self::TOTALS_WIDTH - 32);
        $caeDueDate = $pt(self::CAE_DUE_DATE_FONT_SIZE);

        return <<<CSS
@page { margin: {$topMargin} 40pt 36pt 40pt; }
body { margin: 0; padding: 0; }
body { font-family: {$font}; font-size: 9pt; line-height: 1.35; color: {$text}; }
table { border-collapse: collapse; border-spacing: 0; }
td, th { padding: 0; vertical-align: top; text-align: left; font-weight: normal; }
.b { font-family: {$bold}; }
.muted { color: {$muted}; }
.stack > div + div { margin-top: 1pt; }

/* L3: the "recuadro" is a thin rounded rectangle, fixed on every sheet. */
.header-fixed { position: fixed; top: {$headerTop}; left: 0; right: 0; }
.header { border: {$outline}; border-radius: 12pt; }
.header-table { width: 100%; }
.header-side { width: {$sideWidth}; height: {$boxHeight}; padding: 14pt; }
.header-center { width: 84pt; padding-top: 14pt; text-align: center; }
/* L4: the letter stands out by its size and weight, in an outlined box. */
.letter-box { width: 40pt; height: 40pt; margin: 0 auto; border: {$outline}; border-radius: 8pt; text-align: center; }
.letter { font-family: {$bold}; font-size: 24pt; line-height: 34.4pt; color: {$accent}; }
.code { margin-top: 5pt; font-size: 7.5pt; line-height: 1.2; color: {$text}; }
.letter-legend { margin-top: 5pt; font-family: {$bold}; font-size: 6.5pt; line-height: 1.2; text-align: center; }
.issuer-name { font-family: {$bold}; font-size: 11pt; color: {$accent}; margin-bottom: 2pt; }
.brand { margin-bottom: 10pt; }
.brand img { max-height: 40pt; max-width: 140pt; }
.details { margin-top: 4pt; color: {$text}; }
.stack > div.condition-legend { margin-top: 2pt; color: {$text}; }
.title { font-family: {$bold}; font-size: 18pt; line-height: 1.1; color: {$accent}; }
.stack > div.number { font-family: {$bold}; font-size: 10pt; margin-top: 1pt; margin-bottom: 6pt; }

.section { margin-top: 18pt; }
.section-table { width: 100%; }
.column-left { padding-right: 14pt; }
.column-right { width: {$rightColumn}; padding-left: 14pt; }
.section-title { font-family: {$bold}; font-size: 9pt; color: {$text}; margin-bottom: 3pt; }
.notes { margin-top: 14pt; color: {$text}; }

.lines { width: 100%; margin-top: 6pt; }
.lines thead td { padding-top: 18pt; padding-bottom: 5pt; border-bottom: 1pt solid {$accent}; font-size: 7.5pt; color: {$text}; }
.lines tbody td { padding-top: 3pt; padding-bottom: 3pt; border-bottom: 0.5pt solid {$border}; }
.lines tbody tr.continued td { border-bottom: 0; }
.lines tbody tr.continuation td { padding-top: 0; }
.cell-code { width: 64pt; padding-right: 8pt; }
.cell-description { padding-right: 6pt; word-wrap: break-word; }
.cell-number { width: 64pt; text-align: right; }
.cell-discount { width: 52pt; text-align: right; }
.cell-narrow { width: 40pt; text-align: right; }

.totals { width: 100%; margin-top: 10pt; }
.totals.grand { margin-top: 0; }
.aside { width: {$asideWidth}; padding-right: 32pt; padding-top: 5pt; }
.total-label { padding-top: 1pt; padding-bottom: 1pt; padding-right: 8pt; border-bottom: 0.5pt solid {$border}; }
.total-amount { padding-top: 1pt; padding-bottom: 1pt; text-align: right; white-space: nowrap; border-bottom: 0.5pt solid {$border}; }
.grand-total td.total-label, .grand-total td.total-amount { font-family: {$bold}; color: {$accent}; border-bottom: 0; }

/* The total, legends and fiscal footer stay together. */
.closing { page-break-inside: avoid; }
.closing-space { height: {$closingSpace}; }
.legends { margin-top: 20pt; font-size: 7.5pt; line-height: 1.4; color: {$text}; }
.legends div + div { margin-top: 2pt; }

/* L8, G2 and Ley 27.743 bottom left; L9 and Q1 bottom right, on the last sheet. */
.footer { position: absolute; left: 0; right: 0; bottom: 0; height: {$footerHeight}; padding-top: 12pt; border-top: 0.5pt solid {$border}; }
.footer-table { width: 100%; }
.footer-left { vertical-align: bottom; padding-right: 16pt; }
.footer-right { vertical-align: middle; text-align: right; }
.footer-qr { width: {$qr}; height: {$qr}; padding-right: 12pt; vertical-align: middle; }
.footer-qr img { width: {$qr}; height: {$qr}; }
.footer-cae { vertical-align: middle; text-align: left; white-space: nowrap; }
.transparency-title { font-family: {$bold}; font-size: 7.5pt; margin-bottom: 2pt; }
.transparency { font-size: 8pt; color: {$text}; }
.cae { font-family: {$bold}; font-size: 10pt; color: {$accent}; }
.cae-due-date { margin-top: 3pt; font-family: {$bold}; font-size: {$caeDueDate}; color: {$accent}; }
CSS;
    }

    /**
     * A font name as a CSS `font-family` with its weight and style: the
     * standard PDF fonts are faces (`Helvetica-Bold`), dompdf takes the family.
     */
    public static function cssFont(string $name, bool $bold): string
    {
        $standard = self::STANDARD_FONTS[strtolower(trim($name))] ?? null;
        if ($standard !== null) {
            [$family, $weight, $style] = $standard;
            $weight = $bold ? 'bold' : $weight;

            return "{$family}; font-weight: {$weight}; font-style: {$style}";
        }

        return "'".str_replace(["'", '\\', ';', '{', '}', '<', '>'], '', $name)."'; font-weight: ".($bold ? 'bold' : 'normal').'; font-style: normal';
    }

    /** A colour as a CSS value, without anything that could end the declaration. */
    private static function cssValue(string $value): string
    {
        return str_replace([';', '{', '}', '<', '>', '"', "'", '\\'], '', trim($value));
    }
}
