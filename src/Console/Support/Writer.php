<?php

namespace Vant\Facturas\Console\Support;

/** One line per fact. Colors land on the mark and nowhere else (`CliWriter`). */
final class Writer
{
    /** Column where a layer detail starts, so the marks line up like a shell. */
    private const LABEL_WIDTH = 22;

    private const INDENT = '  ';

    /** @var callable(string): void */
    private $write;

    /** @param  callable(string): void  $write */
    public function __construct(callable $write, public readonly Painter $painter)
    {
        $this->write = $write;
    }

    public function ok(string $label, ?string $detail = null): void
    {
        $this->mark($this->painter->green('✓'), $label, $detail);
    }

    public function fail(string $label, ?string $detail = null): void
    {
        $this->mark($this->painter->red('✗'), $label, $detail);
    }

    public function warn(string $label, ?string $detail = null): void
    {
        $this->mark($this->painter->yellow('!'), $label, $detail);
    }

    /** An indented continuation line: a diagnosis, a fix or one sales point. */
    public function note(string $text): void
    {
        ($this->write)(self::INDENT.$text."\n");
    }

    public function line(string $text): void
    {
        ($this->write)($text."\n");
    }

    /** A whole line in dim: an aside the reader can skip without losing the plot. */
    public function dim(string $text): void
    {
        ($this->write)($this->painter->dim($text)."\n");
    }

    /** An indented shell line: a dim `$` and the command in cyan. */
    public function command(string $text): void
    {
        ($this->write)(self::INDENT.$this->painter->dim('$').' '.$this->painter->cyan($text)."\n");
    }

    public function blank(): void
    {
        ($this->write)("\n");
    }

    public function json(mixed $value): void
    {
        ($this->write)(Output::json($value)."\n");
    }

    /** Raw text with no newline added: a prompt. */
    public function raw(string $text): void
    {
        ($this->write)($text);
    }

    private function mark(string $symbol, string $label, ?string $detail): void
    {
        $body = $detail === null ? $label : self::padEnd($label, self::LABEL_WIDTH).' '.$detail;
        ($this->write)("{$symbol} {$body}\n");
    }

    /** `String.prototype.padEnd`, counting characters rather than bytes. */
    private static function padEnd(string $text, int $width): string
    {
        $length = mb_strlen($text);

        return $length >= $width ? $text : $text.str_repeat(' ', $width - $length);
    }
}
