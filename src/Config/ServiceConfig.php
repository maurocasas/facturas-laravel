<?php

namespace Vant\Facturas\Config;

use Vant\Facturas\Errors\ArcaConfigurationError;

/**
 * Endpoints and SOAP settings of each ARCA web service.
 *
 * @internal
 */
final class ServiceConfig
{
    public const WSAA = [
        'namespace' => 'http://wsaa.view.sua.dvadac.desein.afip.gov',
        'endpoint' => [
            'production' => 'https://wsaa.afip.gov.ar/ws/services/LoginCms',
            'test' => 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms',
        ],
        'soapVersion' => '1.1',
        'soapActionBase' => '',
        'usesEmptySoapAction' => true,
    ];

    public const SERVICES = [
        'wsaa' => self::WSAA,
        'wsfe' => [
            'namespace' => 'http://ar.gov.afip.dif.FEV1/',
            'endpoint' => [
                'production' => 'https://servicios1.afip.gov.ar/wsfev1/service.asmx',
                'test' => 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx',
            ],
            'soapVersion' => '1.2',
            'soapActionBase' => 'http://ar.gov.afip.dif.FEV1/',
            'useLegacyTlsSecurityLevel0' => true,
        ],
        'wsfex' => [
            'namespace' => 'http://ar.gov.afip.dif.fexv1/',
            'endpoint' => [
                'production' => 'https://servicios1.afip.gov.ar/wsfexv1/service.asmx',
                'test' => 'https://wswhomo.afip.gov.ar/wsfexv1/service.asmx',
            ],
            'soapVersion' => '1.1',
            'soapActionBase' => 'http://ar.gov.afip.dif.fexv1/',
            'useLegacyTlsSecurityLevel0' => true,
        ],
        'wsmtxca' => [
            'namespace' => 'http://impl.service.wsmtxca.afip.gov.ar/service/',
            'endpoint' => [
                'production' => 'https://serviciosjava.afip.gov.ar/wsmtxca/services/MTXCAService',
                'test' => 'https://fwshomo.afip.gov.ar/wsmtxca/services/MTXCAService',
            ],
            'soapVersion' => '1.1',
            'soapActionBase' => 'http://impl.service.wsmtxca.afip.gov.ar/service/',
        ],
        'padron-a5' => [
            'namespace' => 'http://a5.soap.ws.server.puc.sr/',
            'endpoint' => [
                'production' => 'https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA5',
                'test' => 'https://awshomo.afip.gov.ar/sr-padron/webservices/personaServiceA5',
            ],
            'soapVersion' => '1.1',
            'soapActionBase' => '',
            'usesEmptySoapAction' => true,
        ],
        'padron-a13' => [
            'namespace' => 'http://a13.soap.ws.server.puc.sr/',
            'endpoint' => [
                'production' => 'https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA13',
                'test' => 'https://awshomo.afip.gov.ar/sr-padron/webservices/personaServiceA13',
            ],
            'soapVersion' => '1.1',
            'soapActionBase' => '',
            'usesEmptySoapAction' => true,
        ],
    ];

    /** @return array{namespace: string, endpoint: array<string, string>, soapVersion: string, soapActionBase: string, usesEmptySoapAction?: bool, useLegacyTlsSecurityLevel0?: bool} */
    public static function get(string $service): array
    {
        return self::SERVICES[$service] ?? throw new ArcaConfigurationError("Unsupported ARCA service configuration: {$service}");
    }
}
