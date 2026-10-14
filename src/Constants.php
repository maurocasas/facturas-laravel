<?php

namespace Facturas;

/** Common ARCA reference data for readable userland code and examples (`facturas/constants`). */
final class Constants
{
    public const ARCA_VOUCHER_TYPES = [
        'FACTURA_A' => 1,
        'NOTA_DEBITO_A' => 2,
        'NOTA_CREDITO_A' => 3,
        'FACTURA_B' => 6,
        'NOTA_DEBITO_B' => 7,
        'NOTA_CREDITO_B' => 8,
        'FACTURA_C' => 11,
        'NOTA_DEBITO_C' => 12,
        'NOTA_CREDITO_C' => 13,
        'FACTURA_E' => 19,
        'NOTA_DEBITO_E' => 20,
        'NOTA_CREDITO_E' => 21,
    ];

    /** Common document types accepted by ARCA services. */
    public const ARCA_DOCUMENT_TYPES = [
        'CUIT' => 80,
        'DNI' => 96,
        'CONSUMIDOR_FINAL' => 99,
    ];

    /**
     * ARCA's generic WSMTXCA item codes, keyed by the `matrixCode` to send.
     * RG 2904, Anexo VII. Samples and promotional material use `7790001001054` (Ventas varias).
     */
    public const ARCA_WSMTXCA_GENERIC_CODES = [
        '7790001001030' => 'Descuentos y bonificaciones comerciales',
        '7790001001047' => 'Conceptos financieros',
        '7790001001054' => 'Ventas varias',
        '7790001001061' => 'Bienes de uso',
        '7790001001078' => 'Servicios prestados',
        '7790001001085' => 'Fletes',
        '7790001001092' => 'Alquileres',
        '7790001001115' => 'Depósito y servicios de logística',
        '7790001001122' => 'Repuestos y accesorios',
        '7790001001139' => 'Ajustes impositivos',
        '7790001001146' => 'Actividades comerciales no codificadas',
        '7790001001153' => 'Venta de material de rezago',
        '7790001001856' => 'Anticipos y señas',
    ];

    /** Common receiver IVA condition identifiers used by WSFE. */
    public const ARCA_RECEIVER_VAT_CONDITIONS = [
        'RESPONSABLE_INSCRIPTO' => 1,
        'EXENTO' => 4,
        'CONSUMIDOR_FINAL' => 5,
        'MONOTRIBUTISTA' => 6,
        'IVA_NO_ALCANZADO' => 15,
    ];

    /** Supported invoice concept types for WSFE requests. */
    public const ARCA_CONCEPT_TYPES = [
        'PRODUCTOS' => 1,
        'SERVICIOS' => 2,
        'PRODUCTOS_Y_SERVICIOS' => 3,
    ];

    /** Common IVA rate identifiers used by WSFE. */
    public const ARCA_VAT_RATES = [
        'IVA_0' => 3,
        'IVA_10_5' => 4,
        'IVA_21' => 5,
        'IVA_27' => 6,
        'IVA_5' => 8,
        'IVA_2_5' => 9,
    ];

    /** ISO currency codes accepted by the high-level WSFE builders. */
    public const ISO_CURRENCIES = [
        'ARS' => 'ARS',
        'USD' => 'USD',
    ];

    /** ARCA currency identifiers keyed by their corresponding ISO currency. */
    public const ARCA_CURRENCY_IDS = [
        'ARS' => 'PES',
        'USD' => 'DOL',
    ];

    /** Legal assertions supported by the invoice API; never inferred from Padrón. */
    public const ISSUER_CONDITIONS = ['responsable_inscripto', 'monotributo', 'exento', 'no_alcanzado'];

    public const RECEIVER_CONDITIONS = ['responsable_inscripto', 'monotributo', 'exento', 'no_alcanzado', 'consumidor_final'];

    public const ARCA_RECEIVER_CONDITION_IDS = [
        'responsable_inscripto' => 1,
        'monotributo' => 6,
        'exento' => 4,
        'consumidor_final' => 5,
        'no_alcanzado' => 15,
    ];

    /** The ARCA identifier of each issuer condition, accepted wherever a name is. */
    public const ARCA_ISSUER_CONDITION_IDS = [
        'responsable_inscripto' => 1,
        'monotributo' => 6,
        'exento' => 4,
        'no_alcanzado' => 15,
    ];

    /** RG 5866/2026, art. 1(f), effective 2026-07-01 (art. 4). In minor units (cents). */
    public const ARCA_FINAL_CONSUMER_IDENTIFICATION_THRESHOLD_MINOR_UNITS = 1_000_000_000;

    /** WSFE v4.7, validations 10243/10246; issuer asserted first. */
    public const ARCA_INVOICE_CLASS_BY_ISSUER = [
        'responsable_inscripto' => [
            'responsable_inscripto' => 'A',
            'monotributo' => 'A',
            'exento' => 'B',
            'consumidor_final' => 'B',
            'no_alcanzado' => 'B',
        ],
        'monotributo' => [
            'responsable_inscripto' => 'C',
            'monotributo' => 'C',
            'exento' => 'C',
            'consumidor_final' => 'C',
            'no_alcanzado' => 'C',
        ],
        'exento' => [
            'responsable_inscripto' => 'C',
            'monotributo' => 'C',
            'exento' => 'C',
            'consumidor_final' => 'C',
            'no_alcanzado' => 'C',
        ],
        'no_alcanzado' => [
            'responsable_inscripto' => 'C',
            'monotributo' => 'C',
            'exento' => 'C',
            'consumidor_final' => 'C',
            'no_alcanzado' => 'C',
        ],
    ];
}
