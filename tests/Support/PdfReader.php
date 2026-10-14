<?php

namespace Vant\Facturas\Tests\Support;

use RuntimeException;

/**
 * Reads back what a PDF dompdf rendered shows: text runs with position and
 * size, and links. Port of `packages/pdf/src/pdf-reader.test-helper.ts` for
 * dompdf's output (standard fonts, WinAnsi strings, `Td` positioning), with
 * no PDF library.
 */
final class PdfReader
{
    /**
     * @return list<array{width: float, height: float, texts: list<array{page: int, text: string, x: float, top: float, fontSize: float}>, links: list<array{url: string, x: float, top: float}>}>
     */
    public static function read(string $bytes): array
    {
        $objects = self::objects($bytes);
        $pages = [];
        foreach (self::pageOrder($objects) as $number => $id) {
            $page = $objects[$id];
            $height = 841.89;
            $width = 595.28;
            if (preg_match('/\/MediaBox\s*\[\s*([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s*\]/', $page['dict'], $box) === 1) {
                $width = (float) $box[3];
                $height = (float) $box[4];
            }
            $texts = [];
            foreach (self::references($page['dict'], 'Contents') as $contentId) {
                $content = $objects[$contentId]['stream'] ?? '';
                foreach (self::mergeRuns(self::textRuns($content)) as $run) {
                    if (trim($run['text']) === '') {
                        continue;
                    }
                    $texts[] = [
                        'page' => $number + 1,
                        'text' => $run['text'],
                        'x' => $run['x'],
                        'top' => $height - $run['y'],
                        'fontSize' => round($run['size'] * 10) / 10,
                    ];
                }
            }
            $links = [];
            foreach (self::references($page['dict'], 'Annots') as $annotId) {
                $annot = $objects[$annotId]['dict'] ?? '';
                if (preg_match('/\/A\s+(\d+)\s+0\s+R/', $annot, $action) === 1) {
                    $annot .= $objects[(int) $action[1]]['dict'] ?? '';
                }
                if (preg_match('/\/URI\s*\((.*?)(?<!\\\\)\)/s', $annot, $uri) === 1
                    && preg_match('/\/Rect\s*\[\s*([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s*\]/', $annot, $rect) === 1) {
                    $links[] = ['url' => self::unescape($uri[1]), 'x' => (float) $rect[1], 'top' => $height - (float) $rect[4]];
                }
            }
            $pages[] = ['width' => $width, 'height' => $height, 'texts' => $texts, 'links' => $links];
        }

        return $pages;
    }

    /** The first run on the page whose text contains `$fragment`. */
    public static function find(array $page, string $fragment): array
    {
        foreach ($page['texts'] as $text) {
            if (str_contains($text['text'], $fragment)) {
                return $text;
            }
        }
        throw new RuntimeException(
            "\"{$fragment}\" is not on the page. Runs: ".implode(' | ', array_column($page['texts'], 'text'))
        );
    }

    /** The first run on the page whose whole text is `$text`. */
    public static function findExact(array $page, string $text): array
    {
        foreach ($page['texts'] as $run) {
            if (trim($run['text']) === $text) {
                return $run;
            }
        }
        throw new RuntimeException("\"{$text}\" is not a run on the page.");
    }

    public static function hasText(array $page, string $fragment): bool
    {
        foreach ($page['texts'] as $text) {
            if (str_contains($text['text'], $fragment)) {
                return true;
            }
        }

        return false;
    }

    /** Every run of the page, joined by spaces. */
    public static function allText(array $page): string
    {
        return implode(' ', array_column($page['texts'], 'text'));
    }

    /**
     * @return array<int, array{dict: string, stream?: string}>
     */
    private static function objects(string $bytes): array
    {
        $objects = [];
        preg_match_all('/(\d+)\s+0\s+obj\b(.*?)\bendobj/s', $bytes, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $body = $match[2];
            $object = ['dict' => $body];
            if (preg_match('/^(.*?)stream\r?\n(.*)\r?\nendstream/s', $body, $stream) === 1) {
                $object['dict'] = $stream[1];
                $data = $stream[2];
                if (str_contains($stream[1], '/FlateDecode')) {
                    $inflated = @gzuncompress($data);
                    $data = $inflated === false ? $data : $inflated;
                }
                $object['stream'] = $data;
            }
            $objects[(int) $match[1]] = $object;
        }

        return $objects;
    }

    /**
     * The page objects in the order of the page tree.
     *
     * @param  array<int, array{dict: string}>  $objects
     * @return list<int>
     */
    private static function pageOrder(array $objects): array
    {
        foreach ($objects as $object) {
            if (preg_match('/\/Type\s*\/Pages\b/', $object['dict']) === 1 && ! str_contains($object['dict'], '/Parent')) {
                return self::kids($objects, $object['dict']);
            }
        }

        return [];
    }

    /** @return list<int> */
    private static function kids(array $objects, string $dict): array
    {
        $pages = [];
        foreach (self::references($dict, 'Kids') as $id) {
            $kid = $objects[$id]['dict'] ?? '';
            if (preg_match('/\/Type\s*\/Pages\b/', $kid) === 1) {
                array_push($pages, ...self::kids($objects, $kid));
            } else {
                $pages[] = $id;
            }
        }

        return $pages;
    }

    /** @return list<int> */
    private static function references(string $dict, string $key): array
    {
        if (preg_match('/\/'.$key.'\s*\[([^\]]*)\]/', $dict, $list) === 1) {
            preg_match_all('/(\d+)\s+0\s+R/', $list[1], $ids);

            return array_map('intval', $ids[1]);
        }
        if (preg_match('/\/'.$key.'\s+(\d+)\s+0\s+R/', $dict, $one) === 1) {
            return [(int) $one[1]];
        }

        return [];
    }

    /**
     * @return list<array{text: string, x: float, y: float, size: float}>
     */
    private static function textRuns(string $content): array
    {
        $runs = [];
        preg_match_all('/BT\b(.*?)\bET/s', $content, $blocks);
        foreach ($blocks[1] as $block) {
            $x = 0.0;
            $y = 0.0;
            $size = 0.0;
            if (preg_match('/([\d.\-]+)\s+([\d.\-]+)\s+Td/', $block, $td) === 1) {
                $x = (float) $td[1];
                $y = (float) $td[2];
            }
            if (preg_match('/[\d.\-]+\s+[\d.\-]+\s+[\d.\-]+\s+[\d.\-]+\s+([\d.\-]+)\s+([\d.\-]+)\s+Tm/', $block, $tm) === 1) {
                $x = (float) $tm[1];
                $y = (float) $tm[2];
            }
            if (preg_match('/\/F\w*\s+([\d.]+)\s+Tf/', $block, $tf) === 1) {
                $size = (float) $tf[1];
            }
            $text = '';
            preg_match_all('/\((?:\\\\.|[^\\\\)])*\)|-?[\d.]+(?=\s*[^\d.\s]|\s)/s', self::showOperands($block), $parts);
            foreach ($parts[0] as $part) {
                if ($part[0] === '(') {
                    $text .= self::unescape(substr($part, 1, -1));
                } elseif ((float) $part < -200) {
                    $text .= ' ';
                }
            }
            $runs[] = ['text' => mb_convert_encoding($text, 'UTF-8', 'Windows-1252'), 'x' => $x, 'y' => $y, 'size' => $size];
        }

        return $runs;
    }

    /**
     * dompdf shows each inline box apart, so a label and its value, as
     * "CUIT:" and " 20-12345678-9", are two runs. A reader of the page sees one
     * line: runs that continue each other on the same baseline are joined.
     *
     * @param  list<array{text: string, x: float, y: float, size: float}>  $runs
     * @return list<array{text: string, x: float, y: float, size: float}>
     */
    private static function mergeRuns(array $runs): array
    {
        $merged = [];
        foreach ($runs as $run) {
            $last = count($merged) - 1;
            if ($last >= 0) {
                $previous = $merged[$last];
                $end = $previous['x'] + mb_strlen($previous['text'], 'UTF-8') * $previous['size'] * 0.62;
                if (abs($previous['y'] - $run['y']) < 0.5 && $run['x'] > $previous['x'] && $run['x'] - $end < 3) {
                    $merged[$last]['text'] .= $run['text'];

                    continue;
                }
            }
            $merged[] = $run;
        }

        return $merged;
    }

    /** The operands of the block's `Tj` and `TJ` operators. */
    private static function showOperands(string $block): string
    {
        preg_match_all('/(\[(?:\((?:\\\\.|[^\\\\)])*\)|[^\]])*\])\s*TJ|(\((?:\\\\.|[^\\\\)])*\))\s*Tj/s', $block, $shows, PREG_SET_ORDER);
        $operands = '';
        foreach ($shows as $show) {
            $operands .= ($show[1] !== '' ? $show[1] : $show[2]).' ';
        }

        return $operands;
    }

    private static function unescape(string $value): string
    {
        return (string) preg_replace_callback(
            '/\\\\([nrtbf()\\\\]|[0-7]{1,3})/',
            static function (array $match): string {
                return match ($match[1]) {
                    'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C",
                    '(' => '(', ')' => ')', '\\' => '\\',
                    default => chr(octdec($match[1])),
                };
            },
            $value
        );
    }
}
