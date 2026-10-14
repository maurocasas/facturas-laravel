<?php

namespace Vant\Facturas\Wsaa;

interface WsaaAuthModule
{
    /**
     * Returns a valid WSAA ticket for the service, reusing a cached or stored one.
     *
     * @param  'wsfe'|'wsfex'|'wsmtxca'|'ws_sr_constancia_inscripcion'|'ws_sr_padron_a13'  $service
     * @param  array{forceRefresh?: bool, representedTaxId?: int|string|null, abortSignal?: \Vant\Facturas\Support\AbortSignal|null}  $options
     * @return array{token: string, sign: string, expiresAt: string}
     */
    public function login(string $service, array $options = []): array;
}
