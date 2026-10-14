<?php

namespace Vant\Facturas\Support;

/** Mirrors the Web `AbortController`: owns a signal and aborts it on demand. */
final class AbortController
{
    public readonly AbortSignal $signal;

    public function __construct()
    {
        $this->signal = AbortSignal::manual();
    }

    public function abort(mixed $reason = null): void
    {
        $this->signal->abort($reason);
    }
}
