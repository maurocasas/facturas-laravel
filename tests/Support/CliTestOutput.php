<?php

namespace Facturas\Tests\Support;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/** A console output with stdout and stderr kept apart, in memory, without ANSI. */
final class CliTestOutput extends StreamOutput implements ConsoleOutputInterface
{
    private OutputInterface $stderr;

    /** @var list<ConsoleSectionOutput> */
    private array $sections = [];

    public function __construct(bool $decorated = false)
    {
        parent::__construct(fopen('php://memory', 'w+'), self::VERBOSITY_NORMAL, $decorated);
        $this->stderr = new StreamOutput(fopen('php://memory', 'w+'), self::VERBOSITY_NORMAL, $decorated);
    }

    public function getErrorOutput(): OutputInterface
    {
        return $this->stderr;
    }

    public function setErrorOutput(OutputInterface $error): void
    {
        $this->stderr = $error;
    }

    public function section(): ConsoleSectionOutput
    {
        return new ConsoleSectionOutput($this->getStream(), $this->sections, $this->getVerbosity(), $this->isDecorated(), $this->getFormatter());
    }

    public function stdout(): string
    {
        return self::read($this->getStream());
    }

    public function stderr(): string
    {
        return $this->stderr instanceof StreamOutput ? self::read($this->stderr->getStream()) : '';
    }

    /** @param  resource  $stream */
    private static function read($stream): string
    {
        $position = ftell($stream);
        rewind($stream);
        $contents = (string) stream_get_contents($stream);
        fseek($stream, (int) $position);

        return $contents;
    }
}
