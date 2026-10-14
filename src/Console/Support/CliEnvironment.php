<?php

namespace Facturas\Console\Support;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Facturas\ArcaClient;
use Facturas\Config\ArcaConfig;
use Facturas\Internal\Logger;
use Facturas\Wsaa\WsaaAuth;
use Facturas\Wsaa\WsaaAuthModule;

/**
 * Everything the CLI touches outside itself: the clock, the ticket cache, the
 * clipboard, the terminal and the SDK (the factories of `CliIo` in the
 * TypeScript CLI). Bound in the container, so tests swap it for a fake and
 * stay offline.
 */
class CliEnvironment
{
    public function now(): DateTimeImmutable
    {
        return Carbon::now()->toDateTimeImmutable();
    }

    /** Where the WSAA ticket is cached between runs. Never holds anything else. */
    public function cacheDir(): string
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'facturas-cli';
    }

    /**
     * Puts text in the system clipboard, answering whether it got there.
     * Nothing else in the CLI touches the clipboard, and a `false` is never
     * an error.
     */
    public function copyToClipboard(string $text): bool
    {
        return Clipboard::copyToClipboard($text);
    }

    /**
     * True when the CLI may ask a question. Without a terminal on stdin every
     * missing value has to arrive as a flag, so scripts and CI fail fast
     * instead of hanging.
     *
     * @param  resource  $stdin
     */
    public function isInteractive($stdin): bool
    {
        return @stream_isatty($stdin);
    }

    /** @return array<string, string> The process environment, for the color and clipboard guards. */
    public function env(): array
    {
        return getenv();
    }

    public function createClient(ArcaConfig $config): ArcaClient
    {
        return new ArcaClient($config);
    }

    public function createAuth(ArcaConfig $config): WsaaAuthModule
    {
        return new WsaaAuth($config, new Logger($config->logger));
    }
}
