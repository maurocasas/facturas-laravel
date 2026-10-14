<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Credenciales de ARCA
    |--------------------------------------------------------------------------
    |
    | CUIT del emisor, certificado y clave privada (PEM) y entorno. Podés pasar
    | el PEM directo (ARCA_CERTIFICATE_PEM / ARCA_PRIVATE_KEY_PEM) o la ruta a
    | los archivos (ARCA_CERTIFICATE_PATH / ARCA_PRIVATE_KEY_PATH). El entorno
    | no tiene valor por defecto: "test" (homologación) o "production".
    |
    */

    'tax_id' => env('ARCA_TAX_ID'),

    'certificate' => env('ARCA_CERTIFICATE_PEM'),
    'certificate_path' => env('ARCA_CERTIFICATE_PATH'),

    'private_key' => env('ARCA_PRIVATE_KEY_PEM'),
    'private_key_path' => env('ARCA_PRIVATE_KEY_PATH'),

    'environment' => env('ARCA_ENVIRONMENT'),

    /*
    |--------------------------------------------------------------------------
    | Transporte
    |--------------------------------------------------------------------------
    |
    | timeout y retry_delay en milisegundos. Los reintentos solo aplican a
    | fallas de transporte, nunca a la autorización de un comprobante.
    |
    */

    'timeout' => (int) env('ARCA_TIMEOUT', 30000),
    'retries' => (int) env('ARCA_RETRIES', 0),
    'retry_delay' => (int) env('ARCA_RETRY_DELAY', 500),

    /*
    |--------------------------------------------------------------------------
    | Store
    |--------------------------------------------------------------------------
    |
    | Guarda los tickets de WSAA (cifrados) y las reservas que hacen idempotente
    | la emisión con `idempotencyKey`. Drivers:
    |
    |   null      sin store: tickets solo en memoria, sin idempotencyKey
    |   database  tabla `arca_store` (publicá y corré la migración)
    |   redis     una conexión de config/database.php
    |   cache     un store de cache con locks (redis, memcached, database, file...)
    |   file      un directorio local privado (un solo servidor)
    |   memory    en memoria (tests)
    |
    */

    'store' => [
        'driver' => env('ARCA_STORE', 'database'),
        'connection' => env('ARCA_STORE_CONNECTION'),
        'table' => env('ARCA_STORE_TABLE', 'arca_store'),
        'cache_store' => env('ARCA_STORE_CACHE'),
        'path' => env('ARCA_STORE_PATH', storage_path('app/private/arca')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logs
    |--------------------------------------------------------------------------
    |
    | Nivel mínimo: debug, info, warn o error. En debug se loguean los requests
    | SOAP, los tiempos y el origen del ticket WSAA. Nunca se loguean token,
    | sign ni la clave privada.
    |
    */

    'log' => [
        'enabled' => env('ARCA_LOG_ENABLED', true),
        'level' => env('ARCA_LOG_LEVEL', 'warn'),
        'channel' => env('ARCA_LOG_CHANNEL'),
    ],

];
