<?php

namespace Vant\Facturas\Services;

use Vant\Facturas\Config\ArcaConfig;
use Vant\Facturas\Errors\ArcaSoapFaultError;
use Vant\Facturas\Internal\Authentication;
use Vant\Facturas\Internal\Js;
use Vant\Facturas\Soap\SoapTransport;
use Vant\Facturas\Wsaa\WsaaAuthModule;

/**
 * Padron taxpayer registry service (`createPadronService` in the TypeScript SDK).
 *
 * PadronTax: {id: int, description?: string, state?: string (`AC` is active), regime: 'general'|'monotributo'}
 * PadronAddress: {street?, city?, postalCode?, provinceId?: int, province?}
 * PadronActivity: {id: string (six-digit NAES code), description?, order?: int, since?: 'YYYY-MM', regime}
 * PadronTaxpayerResult: {taxId, personType?, name?, address?, condition?, taxes, activities, errors, raw}
 * PadronTaxIdLookupResult: {taxIds: list<string>, raw}
 */
final class PadronService
{
    private const IVA_TAX_CONDITIONS = [
        20 => 'monotributo',
        30 => 'responsable_inscripto',
        32 => 'exento',
        34 => 'no_alcanzado',
    ];

    // The constancia answers a missing CUIT inside errorConstancia, not as a fault.
    private const NOT_FOUND_PHRASES = [
        'la clave solicitada no existe',
        'no existe persona con ese id',
        'no existe persona con esa clave',
        'persona no encontrada',
        'no se encontro informacion para la clave',
    ];

    public function __construct(
        private readonly ArcaConfig $config,
        private readonly WsaaAuthModule $auth,
        private readonly SoapTransport $soap,
    ) {}

    /**
     * Looks up taxpayer details by CUIT. Returns `null` if the taxpayer does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function getTaxpayerDetails(int|string $taxId): ?array
    {
        $raw = $this->executePadronOperation('padron-a5', 'getPersona_v2', [
            'idPersona' => self::parseInt(Js::str($taxId)),
        ]);
        if (! self::truthy($raw)) {
            return null;
        }
        $record = $raw;
        if (self::isPadronNotFound($record)) {
            return null;
        }
        $datosGenerales = self::prop($record, 'datosGenerales');
        $datosGenerales = is_array($datosGenerales) ? $datosGenerales : null;
        $idPersona = self::prop($record, 'idPersona') ?? self::prop($datosGenerales, 'idPersona');
        $tipoPersona = self::prop($record, 'tipoPersona') ?? self::prop($datosGenerales, 'tipoPersona');
        $taxes = self::extractPadronTaxes($record);
        $errors = self::constanciaErrors($record);
        // A constancia with an unresolved error may be missing registrations, so
        // their absence proves nothing about the receiver's condition.
        $condition = count($errors) > 0 ? null : self::deriveReceiverCondition($taxes);
        $address = self::extractPadronAddress(self::prop($datosGenerales, 'domicilioFiscal'));

        $result = ['taxId' => self::jsString($idPersona ?? $taxId)];
        if ($tipoPersona !== null) {
            $result['personType'] = self::jsString($tipoPersona);
        }
        if ($datosGenerales !== null) {
            $name = self::extractPadronName($datosGenerales);
            if ($name !== null) {
                $result['name'] = $name;
            }
        }
        if ($address !== null) {
            $result['address'] = $address;
        }
        if ($condition !== null) {
            $result['condition'] = $condition;
        }
        $result['taxes'] = $taxes;
        $result['activities'] = self::extractPadronActivities($record);
        $result['errors'] = $errors;
        $result['raw'] = $record;

        return $result;
    }

    /**
     * Looks up CUITs associated with a document number. Returns `null` if not found.
     *
     * @return array{taxIds: list<string>, raw: mixed}|null
     */
    public function getTaxIdByDocument(int|string $documentNumber): ?array
    {
        $raw = $this->executePadronOperation('padron-a13', 'getIdPersonaListByDocumento', [
            'documento' => Js::str($documentNumber),
        ]);
        if (! self::truthy($raw)) {
            return null;
        }
        $record = $raw;
        $idPersona = self::prop($record, 'idPersona');
        $taxIds = Js::isList($idPersona)
            ? array_map(self::jsString(...), $idPersona)
            : ($idPersona === null ? [] : [self::jsString($idPersona)]);

        return [
            'taxIds' => $taxIds,
            'raw' => $record,
        ];
    }

    /** @return list<string> */
    private static function constanciaErrors(mixed $record): array
    {
        $errorConstancia = self::prop($record, 'errorConstancia');
        $errors = self::prop($errorConstancia, 'error');

        return array_values(array_filter(
            Js::isList($errors) ? $errors : [$errors],
            fn ($message) => is_string($message) && self::jsTrim($message) !== '',
        ));
    }

    private static function isPadronNotFound(mixed $record): bool
    {
        foreach (self::constanciaErrors($record) as $message) {
            foreach (self::NOT_FOUND_PHRASES as $phrase) {
                if (str_contains(self::plainText($message), $phrase)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function plainText(string $value): string
    {
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_D);
            if (is_string($normalized)) {
                $value = $normalized;
            }
        }

        return mb_strtolower((string) preg_replace('/\p{Diacritic}/u', '', $value), 'UTF-8');
    }

    /** @return list<array{id: int, description?: string, state?: string, regime: string}> */
    private static function extractPadronTaxes(mixed $record): array
    {
        $taxes = [];
        foreach ([['general', 'datosRegimenGeneral'], ['monotributo', 'datosMonotributo']] as [$regime, $field]) {
            $rows = self::prop(self::prop($record, $field), 'impuesto');
            foreach (Js::isList($rows) ? $rows : (self::truthy($rows) ? [$rows] : []) as $tax) {
                $id = self::toNumber(self::prop($tax, 'idImpuesto'));
                if (! Js::isSafeInteger($id)) {
                    continue;
                }
                $entry = ['id' => (int) $id];
                $description = self::prop($tax, 'descripcionImpuesto');
                if (is_string($description)) {
                    $entry['description'] = $description;
                }
                $state = self::prop($tax, 'estadoImpuesto');
                if (is_string($state)) {
                    $entry['state'] = mb_strtoupper(self::jsTrim($state), 'UTF-8');
                }
                $entry['regime'] = $regime;
                $taxes[] = $entry;
            }
        }

        return $taxes;
    }

    /** @return list<array<string, mixed>> */
    private static function rowsOf(mixed $value): array
    {
        return array_values(array_filter(
            Js::isList($value) ? $value : (self::truthy($value) ? [$value] : []),
            fn ($row) => is_array($row),
        ));
    }

    /** @return list<array<string, mixed>> */
    private static function extractPadronActivities(mixed $record): array
    {
        $activities = [];
        $seen = [];
        foreach ([
            ['general', 'datosRegimenGeneral', 'actividad'],
            ['monotributo', 'datosMonotributo', 'actividad'],
            ['monotributo', 'datosMonotributo', 'actividadMonotributista'],
        ] as [$regime, $field, $key]) {
            $bucket = self::prop($record, $field);
            foreach (self::rowsOf(self::prop($bucket, $key)) as $row) {
                $activity = self::toPadronActivity($row, $regime);
                if ($activity !== null && ! isset($seen["{$regime}:{$activity['id']}"])) {
                    $seen["{$regime}:{$activity['id']}"] = true;
                    $activities[] = $activity;
                }
            }
        }

        return $activities;
    }

    // ARCA answers the NAES code as a number, which drops its leading zero.
    /** @return array<string, mixed>|null */
    private static function toPadronActivity(array $row, string $regime): ?array
    {
        $digits = (string) preg_replace('/\D/', '', self::jsString($row['idActividad'] ?? ''));
        if ($digits === '' || ltrim($digits, '0') === '') {
            return null;
        }
        $order = self::toNumber($row['orden'] ?? null);
        $period = self::jsString($row['periodo'] ?? '');
        $activity = ['id' => str_pad($digits, 6, '0', STR_PAD_LEFT)];
        if (is_string($row['descripcionActividad'] ?? null)) {
            $activity['description'] = $row['descripcionActividad'];
        }
        if (isset($row['orden']) && Js::isSafeInteger($order)) {
            $activity['order'] = (int) $order;
        }
        if (preg_match('/^\d{6}$/D', $period)) {
            $activity['since'] = substr($period, 0, 4).'-'.substr($period, 4);
        }
        $activity['regime'] = $regime;

        return $activity;
    }

    /** @return array<string, mixed>|null */
    private static function extractPadronAddress(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $provinceId = isset($value['idProvincia']) ? self::toNumber($value['idProvincia']) : NAN;
        $address = self::optionalText('street', $value['direccion'] ?? null)
            + self::optionalText('city', $value['localidad'] ?? null)
            + self::optionalText('postalCode', $value['codPostal'] ?? null);
        if (Js::isSafeInteger($provinceId) && self::jsTrim(self::jsString($value['idProvincia'])) !== '') {
            $address['provinceId'] = (int) $provinceId;
        }
        $address += self::optionalText('province', $value['descripcionProvincia'] ?? null);

        return $address === [] ? null : $address;
    }

    /** @return array<string, string> */
    private static function optionalText(string $key, mixed $value): array
    {
        $text = is_string($value)
            ? self::jsTrim($value)
            : (is_int($value) || is_float($value) ? Js::str($value) : '');

        return $text === '' ? [] : [$key => $text];
    }

    /**
     * One active IVA registration decides; none is a consumidor final; two is nobody's call.
     *
     * @param  list<array{id: int, state?: string}>  $taxes
     */
    private static function deriveReceiverCondition(array $taxes): ?string
    {
        $conditions = [];
        foreach ($taxes as $tax) {
            $condition = self::IVA_TAX_CONDITIONS[$tax['id']] ?? null;
            if ($condition !== null && (! isset($tax['state']) || $tax['state'] === 'AC')) {
                $conditions[$condition] = true;
            }
        }
        if (count($conditions) === 0) {
            return 'consumidor_final';
        }

        return count($conditions) === 1 ? array_key_first($conditions) : null;
    }

    private static function extractPadronName(array $datosGenerales): ?string
    {
        if (is_string($datosGenerales['razonSocial'] ?? null)) {
            return $datosGenerales['razonSocial'];
        }
        $nombre = $datosGenerales['nombre'] ?? null;
        $apellido = $datosGenerales['apellido'] ?? null;
        if (is_string($apellido) && is_string($nombre)) {
            return self::jsTrim("{$apellido} {$nombre}");
        }
        if (is_string($apellido)) {
            return $apellido;
        }
        if (is_string($nombre)) {
            return $nombre;
        }

        return null;
    }

    /** @param 'padron-a5'|'padron-a13' $service */
    private function executePadronOperation(string $service, string $operation, array $body): mixed
    {
        try {
            $credentials = $this->auth->login(
                $service === 'padron-a5' ? 'ws_sr_constancia_inscripcion' : 'ws_sr_padron_a13'
            );
        } catch (\Throwable $error) {
            throw Authentication::classifyError($error, ['service' => $service, 'operation' => $operation]) ?? $error;
        }

        try {
            $response = $this->soap->execute([
                'service' => $service,
                'operation' => $operation,
                'bodyElementNamespaceMode' => 'prefix',
                'body' => [
                    'token' => $credentials['token'],
                    'sign' => $credentials['sign'],
                    'cuitRepresentada' => self::parseInt($this->config->taxId),
                    ...$body,
                ],
            ]);

            $operationResponse = $response['result'] ?? null;

            if ($operation === 'getPersona_v2') {
                return self::prop($operationResponse, 'personaReturn');
            }

            if ($operation === 'getIdPersonaListByDocumento') {
                return self::prop($operationResponse, 'idPersonaListReturn');
            }

            return self::prop($operationResponse, 'return');
        } catch (\Throwable $error) {
            if (
                $error instanceof ArcaSoapFaultError
                // Public Padron A5/A13 WSDLs expose only a generic validation fault, so
                // there is no documented not-found-specific fault code to match here.
                // Keep the current message fallback, but treat it as fragile.
                && str_contains(mb_strtolower($error->getMessage(), 'UTF-8'), 'no existe')
            ) {
                return null;
            }

            throw Authentication::classifyError($error, ['service' => $service, 'operation' => $operation]) ?? $error;
        }
    }

    /** `value?.[key]` on decoded data: absent for anything but a map. */
    private static function prop(mixed $value, string $key): mixed
    {
        return is_array($value) ? ($value[$key] ?? null) : null;
    }

    /** JavaScript truthiness for decoded data. */
    private static function truthy(mixed $value): bool
    {
        if (is_string($value)) {
            return $value !== '';
        }
        if (is_float($value)) {
            return $value !== 0.0 && ! is_nan($value);
        }
        if (is_array($value)) {
            return true;
        }

        return (bool) $value;
    }

    /** `String(value)`, including maps and lists. */
    private static function jsString(mixed $value): string
    {
        if (is_array($value)) {
            return array_is_list($value)
                ? implode(',', array_map(fn ($item) => $item === null ? '' : self::jsString($item), $value))
                : '[object Object]';
        }

        return Js::str($value);
    }

    /** `Number(value)`; absent and unparseable values are NaN. */
    private static function toNumber(mixed $value): int|float
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return self::integral($value);
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (! is_string($value)) {
            return NAN;
        }
        $text = self::jsTrim($value);
        if ($text === '') {
            return 0;
        }
        if (preg_match('/^0[xX][0-9a-fA-F]+$/D', $text)) {
            return self::integral((float) hexdec(substr($text, 2)));
        }
        if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/D', $text)) {
            return self::integral((float) $text);
        }

        return NAN;
    }

    private static function integral(float $value): int|float
    {
        return Js::isSafeInteger($value) ? (int) $value : $value;
    }

    /** `Number.parseInt(text, 10)`: NaN when no leading digits. */
    private static function parseInt(string $text): int|float
    {
        if (! preg_match('/^[+-]?\d+/', self::jsTrim($text), $match)) {
            return NAN;
        }
        $number = (float) $match[0];

        return abs($number) <= PHP_INT_MAX ? (int) $match[0] : $number;
    }

    /** `String.prototype.trim`, which also strips Unicode spaces. */
    private static function jsTrim(string $value): string
    {
        return (string) preg_replace('/^[\s\p{Zs}\x{FEFF}]+|[\s\p{Zs}\x{FEFF}]+$/u', '', $value);
    }
}
