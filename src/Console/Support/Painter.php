<?php

namespace Vant\Facturas\Console\Support;

/**
 * Wraps text in one ANSI sequence, or returns it untouched when color is off.
 * The whole CLI paints through this, so `--no-color` output is the same text
 * minus the escapes and nothing else.
 */
final class Painter
{
    private const GREEN = "\e[32m";

    private const RED = "\e[31m";

    private const YELLOW = "\e[33m";

    private const CYAN = "\e[36m";

    private const BOLD = "\e[1m";

    private const DIM = "\e[2m";

    private const RESET = "\e[0m";

    public function __construct(public readonly bool $color) {}

    public function green(string $text): string
    {
        return $this->paint(self::GREEN, $text);
    }

    public function red(string $text): string
    {
        return $this->paint(self::RED, $text);
    }

    public function yellow(string $text): string
    {
        return $this->paint(self::YELLOW, $text);
    }

    public function cyan(string $text): string
    {
        return $this->paint(self::CYAN, $text);
    }

    public function bold(string $text): string
    {
        return $this->paint(self::BOLD, $text);
    }

    public function dim(string $text): string
    {
        return $this->paint(self::DIM, $text);
    }

    private function paint(string $code, string $text): string
    {
        return $this->color ? $code.$text.self::RESET : $text;
    }
}
