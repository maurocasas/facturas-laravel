<?php

namespace Vant\Facturas\Internal;

use Vant\Facturas\Errors\ArcaTransportError;
use Vant\Facturas\Support\AbortSignal;

/** @internal */
final class Abort
{
    /** The caller's deadline, reported as a transport failure with its reason. */
    public static function abortedError(?AbortSignal $signal = null): ArcaTransportError
    {
        $reason = $signal?->reason();

        return new ArcaTransportError('The ARCA call was aborted', [
            'previous' => $reason instanceof \Throwable ? $reason : null,
        ]);
    }

    /** Throws the transport abort error when the signal already fired. */
    public static function throwIfAborted(?AbortSignal $signal): void
    {
        if ($signal?->aborted()) {
            throw self::abortedError($signal);
        }
    }
}
