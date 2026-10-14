<?php

namespace Facturas\Errors;

use Throwable;

/**
 * Narrow error evidence, safe to log or store: the typed fields the error
 * classes declare, never a cause, stack, raw response, response body preview,
 * endpoint URL, fiscal issues or CAE.
 */
final class SafeErrorMetadata
{
    /** @return array<string, mixed> */
    public static function from(mixed $error): array
    {
        if (! $error instanceof Throwable) {
            return ['name' => 'UnknownError', 'message' => self::jsString($error)];
        }
        $metadata = [
            'name' => $error instanceof ArcaError ? $error->name() : (new \ReflectionClass($error))->getShortName(),
            'message' => $error->getMessage(),
        ];
        if (! $error instanceof ArcaError) {
            return $metadata;
        }
        $fields = ['code' => $error->errorCode()];
        if ($error instanceof ArcaInputError) {
            $fields += ['field' => $error->field, 'expected' => $error->expected, 'window' => $error->window];
        } elseif ($error instanceof ArcaAuthenticationError) {
            $fields += ['service' => $error->service, 'operation' => $error->operation, 'reason' => $error->reason, 'providerCode' => $error->providerCode];
        } elseif ($error instanceof ArcaLockTimeoutError) {
            $fields += ['reason' => $error->reason];
        } elseif ($error instanceof ArcaTransportError) {
            $fields += ['statusCode' => $error->statusCode, 'contentType' => $error->contentType];
        } elseif ($error instanceof ArcaSoapFaultError) {
            $fields += ['faultCode' => $error->faultCode];
        } elseif ($error instanceof ArcaInvalidSoapResponseError) {
            $fields += ['service' => $error->service, 'operation' => $error->operation, 'statusCode' => $error->statusCode, 'contentType' => $error->contentType];
        } elseif ($error instanceof ArcaServiceError) {
            $fields += [
                'service' => $error->service,
                'operation' => $error->operation,
                'serviceCode' => $error->serviceCode === null ? null : (string) $error->serviceCode,
                'result' => $error->result,
                'resultLevel' => $error->resultLevel,
            ];
        }
        foreach ($fields as $key => $value) {
            if ($value !== null) {
                $metadata[$key] = $value;
            }
        }

        return $metadata;
    }

    /** `String(value)` for a non-error value. */
    private static function jsString(mixed $value): string
    {
        if ($value === null || is_scalar($value)) {
            return \Facturas\Internal\Js::str($value);
        }
        if (is_array($value) && array_is_list($value)) {
            return implode(',', array_map(fn ($item) => $item === null ? '' : self::jsString($item), $value));
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return '[object Object]';
    }
}
