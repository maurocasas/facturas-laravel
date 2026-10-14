<?php

namespace Facturas\Facades;

use Illuminate\Support\Facades\Facade;
use Facturas\ArcaClient;

/**
 * @method static array issue(array $input, array $options = [])
 * @method static array preview(array $input, array $options = [])
 * @method static array issueCreditNote(array $input, array $options = [])
 * @method static array issueDebitNote(array $input, array $options = [])
 * @method static array previewCreditNote(array $input, array $options = [])
 * @method static array previewDebitNote(array $input, array $options = [])
 * @method static array recover(string $idempotencyKey, array $options = [])
 * @method static array|null lookup(array $voucher, array $options = [])
 * @method static int lastAuthorized(array $sequence, array $options = [])
 *
 * @see ArcaClient
 */
class Arca extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ArcaClient::class;
    }

    public static function wsfe(): \Facturas\Services\WsfeService
    {
        return static::getFacadeRoot()->wsfe;
    }

    public static function wsfex(): \Facturas\Services\WsfexService
    {
        return static::getFacadeRoot()->wsfex;
    }

    public static function wsmtxca(): \Facturas\Services\WsmtxcaService
    {
        return static::getFacadeRoot()->wsmtxca;
    }

    public static function padron(): \Facturas\Services\PadronService
    {
        return static::getFacadeRoot()->padron;
    }
}
