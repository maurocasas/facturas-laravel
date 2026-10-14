<?php

use Vant\Facturas\ArcaClient;

// Ejemplos importables: incluir este archivo no hace ninguna llamada fiscal.

function issueReviewedSale(ArcaClient $arca): array
{
    $sale = [
        'issuer' => 'responsable_inscripto',
        'salesPoint' => 1,
        'to' => ['condition' => 'responsable_inscripto', 'cuit' => '20123456789'],
        // Desglose fiscal ya revisado: el paquete no recalcula el IVA.
        'amounts' => [
            'net' => 10_000, // ARS 100,00 en centavos
            'vat' => 2100,
            'vatRates' => [['id' => 5, 'base' => 10_000, 'amount' => 2100]],
        ],
        // Los tributos suman al total y van aparte del desglose.
        'taxes' => [['id' => 2, 'description' => 'IIBB', 'base' => 10_000, 'rate' => 3, 'amount' => 300]],
        'total' => 12_400,
    ];
    $preview = $arca->preview($sale);
    assert(is_int($preview['request']['totalAmount']) || is_float($preview['request']['totalAmount']));

    return $arca->issue($sale, ['idempotencyKey' => 'sale:example', 'number' => 42]);
}

function issueDetailedInvoice(ArcaClient $arca): array
{
    $input = [
        'issuer' => 'responsable_inscripto',
        'salesPoint' => 1,
        'to' => ['condition' => 'responsable_inscripto', 'cuit' => '20123456789'],
        // Los mismos ítems llevan el detalle de línea que pide WSMTXCA.
        // unitPrice es la única excepción: string decimal en unidades mayores.
        'items' => [[
            'net' => 10_000,
            'vat' => 21,
            'description' => 'Producto',
            'quantity' => 1,
            'unit' => 7,
            'unitPrice' => '100.000000',
            // El GTIN del producto. Lo que no tiene GTIN usa un código genérico
            // de Constants::ARCA_WSMTXCA_GENERIC_CODES; este es "Ventas varias".
            'matrixCode' => '7790001001054',
            'matrixUnits' => 1,
        ]],
    ];
    $preview = $arca->preview($input, ['service' => 'wsmtxca']);
    assert(isset($preview['request']['comprobanteCAERequest']['importeTotal']));

    return $arca->issue($input, ['service' => 'wsmtxca', 'idempotencyKey' => 'detailed:example']);
}

function issueAdjustments(ArcaClient $arca): array
{
    $original = ['salesPoint' => 1, 'voucherType' => 1, 'number' => 42];
    $debit = $arca->issueDebitNote(
        ['for' => $original, 'items' => [['net' => 1000, 'vat' => 21]]],
        ['idempotencyKey' => 'debit:example'],
    );
    if ($debit['kind'] !== 'authorized') {
        return $debit;
    }
    $credit = [
        'for' => [
            'salesPoint' => $debit['voucher']['salesPoint'],
            'voucherType' => $debit['voucher']['voucherType'],
            'number' => $debit['voucher']['number'],
        ],
        'all' => true,
    ];
    // Este método consulta el original una vez. No reserva número ni escribe nada.
    $arca->previewCreditNote($credit);

    return $arca->issueCreditNote($credit, ['idempotencyKey' => 'credit:example']);
}

function previewFce(ArcaClient $arca): array
{
    return $arca->preview([
        'issuer' => 'responsable_inscripto',
        'family' => 'fce',
        'salesPoint' => 1,
        'to' => ['condition' => 'responsable_inscripto', 'cuit' => '20123456789'],
        'items' => [['net' => 10_000, 'vat' => 21]],
        'date' => '2026-09-06',
        'dueDate' => '2026-10-01',
        // FCE: el CBU de 22 dígitos y el vencimiento son obligatorios.
        'fce' => ['cbu' => '1234567890123456789012', 'transfer' => 'SCA'],
    ]);
}
