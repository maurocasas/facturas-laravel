<?php
/*
 * An A4 printed voucher laid out as RG 1415, Anexo II, Apartado B places its
 * data, for dompdf. Port of voucher.tsx and blocks.tsx of @facturas/pdf. Rule
 * ids refer to `.github/PRINTED_VOUCHER_SOURCES.md` of the facturas SDK.
 *
 * Plain PHP on purpose: VoucherPdf renders it through Laravel's view factory
 * when there is one and includes it directly otherwise.
 *
 * Data: $doc (what VoucherDocument::buildVoucherDocument() returned), $css,
 * $title, $qr (the QR image as a data URI), $slots (brand, issuerDetails,
 * receiverDetails, aside and notes as HTML, or null), $layout (what
 * VoucherPdf::layout() derived) and $headerOnly (only the issuer box, to
 * measure it).
 */
use Vant\Facturas\Pdf\Format;

$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$text = static fn ($value): string => nl2br($e($value), false);
$issuer = $doc['issuer'];
$receiver = $doc['receiver'];
$currencyId = $doc['currency']['id'];
$money = static fn ($amount): string => $e(Format::formatMoney($amount, $currencyId));
$labeled = static fn (string $label, string $valueHtml): string => '<span class="muted">'.$e($label).'</span> '.$valueHtml;
$headerOnly = $headerOnly ?? false;
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= $e($title) ?></title>
<meta name="author" content="<?= $e($issuer['legalName']) ?>">
<style>
<?= $css ?>
</style>
</head>
<body>
<?php /* L1, L2, L3 and L4: the issuer box with the letter at its top centre. Fixed: every sheet of a long voucher says whose voucher it is. */ ?>
<div id="facturas-issuer" class="<?= $headerOnly ? '' : 'header-fixed' ?>">
<div class="header">
<table class="header-table"><tr>
<td class="header-side"><div class="stack">
<?php if (($slots['brand'] ?? null) !== null) { ?><div class="brand"><?= $slots['brand'] ?></div><?php } ?>
<?php if (! empty($issuer['tradeName'])) { ?><div class="issuer-name"><?= $e($issuer['tradeName']) ?></div><?php } ?>
<div class="<?= ! empty($issuer['tradeName']) ? 'b' : 'issuer-name' ?>"><?= $e($issuer['legalName']) ?></div>
<div><?= $e($issuer['address']) ?></div>
<div class="condition-legend"><?= $e(Format::formatConditionLegend($issuer['conditionLegend'])) ?></div>
<?php if (($slots['issuerDetails'] ?? null) !== null) { ?><div class="details"><?= $slots['issuerDetails'] ?></div><?php } ?>
</div></td>
<?php /* L4, G4 and G5: the letter, its code and the legend next to the letter A. */ ?>
<td class="header-center">
<div class="letter-box"><span class="letter"><?= $e($doc['voucherClass']) ?></span></div>
<div class="code"><?= $e($layout['text']['code']) ?> <?= $e($doc['code']) ?></div>
<?php if (! empty($doc['letterLegend'])) { ?><div class="letter-legend"><?= $e($doc['letterLegend']) ?></div><?php } ?>
</td>
<td class="header-side"><div class="stack">
<div class="title"><?= $e(Format::formatTitle($doc['title'])) ?></div>
<div class="number">Nº <?= $e($doc['number']) ?></div>
<div><?= $labeled('Fecha de emisión:', $e(Format::formatDate($doc['issueDate']))) ?></div>
<div><?= $labeled('CUIT:', $e(Format::formatTaxId($issuer['taxId']))) ?></div>
<div><?= $labeled('Ingresos Brutos:', $e($issuer['grossIncome'])) ?></div>
<?php if (! empty($issuer['activitiesStartDate'])) { /* D9: the norm's INICIO DE ACTIVIDADES, in the case of the other labels. */ ?>
<div><?= $labeled('Inicio de actividades:', $e(Format::formatDate($issuer['activitiesStartDate']))) ?></div>
<?php } ?>
</div></td>
</tr></table>
</div>
</div>
<?php if (! $headerOnly) { ?>
<?php /* C13 to C17, L5 and L6, C12, C22 and the service period. */ ?>
<div class="section">
<table class="section-table"><tr>
<td class="column-left"><div class="stack">
<div class="section-title">Receptor</div>
<?php if (! empty($receiver['name'])) { ?><div class="b"><?= $e($receiver['name']) ?></div><?php } ?>
<?php if (! empty($receiver['document'])) { ?>
<div><?= $labeled(($receiver['document']['label'] ?? 'Documento').':', $e(Format::formatTaxId($receiver['document']['number'])).' · '.$e(Format::formatConditionLegend($receiver['conditionLegend']))) ?></div>
<?php } else { ?>
<div><?= $e(Format::formatConditionLegend($receiver['conditionLegend'])) ?></div>
<?php } ?>
<?php if (! empty($receiver['address'])) { ?><div><?= $e($receiver['address']) ?></div><?php } ?>
<?php if (($slots['receiverDetails'] ?? null) !== null) { ?><div class="details"><?= $slots['receiverDetails'] ?></div><?php } ?>
</div></td>
<td class="column-right"><div class="stack">
<div class="section-title">Operación</div>
<div><?= $labeled('Condición de venta:', $e($doc['saleConditions'])) ?></div>
<?php if (! empty($doc['servicePeriod'])) { ?>
<div><?= $labeled('Período facturado:', $e(Format::formatDate($doc['servicePeriod']['from'])).' al '.$e(Format::formatDate($doc['servicePeriod']['to']))) ?></div>
<?php } ?>
<?php if (! empty($doc['paymentDueDate'])) { ?>
<div><?= $labeled('Vencimiento para el pago:', $e(Format::formatDate($doc['paymentDueDate']))) ?></div>
<?php } ?>
<?php if (count($doc['remitos'] ?? []) > 0) { ?>
<div><?= $labeled('Remitos:', $e(implode(', ', $doc['remitos']))) ?></div>
<?php } ?>
<?php if (! empty($doc['currency']['exchangeRate'])) { ?>
<div><?= $labeled('Moneda:', $e($currencyId).' · Tipo de cambio '.$e(Format::formatDecimal($doc['currency']['exchangeRate']))) ?></div>
<?php } ?>
</div></td>
</tr></table>
</div>
<?php /* Free text goes before the lines, as Stripe's memo: it may run long and break across sheets. */ ?>
<?php if (($slots['notes'] ?? null) !== null) { ?><div class="notes"><?= $slots['notes'] ?></div><?php } ?>
<?php /* C19 to C21: the lines, without VAT on class A and with VAT on class B. The head repeats on every sheet the lines take. */ ?>
<table class="lines">
<thead><tr>
<?php if ($layout['showCode']) { ?><td class="cell-code">Código</td><?php } ?>
<td class="cell-description">Descripción</td>
<td class="cell-narrow">Cant.</td>
<td class="cell-number">P. unitario</td>
<?php if ($layout['showDiscount']) { ?><td class="cell-discount">Bonif.</td><?php } ?>
<?php if ($layout['showRate']) { ?><td class="cell-narrow">IVA</td><?php } ?>
<td class="cell-number">Subtotal</td>
</tr></thead>
<tbody>
<?php foreach ($layout['lines'] as $row) { $line = $row['line']; ?>
<?php foreach ($row['description'] as $chunk => $description) { $first = $chunk === 0; $last = $chunk === count($row['description']) - 1; ?>
<tr class="<?= $last ? '' : 'continued' ?><?= $first ? '' : ' continuation' ?>">
<?php if ($layout['showCode']) { ?><td class="cell-code"><?= $first ? $e($line['code'] ?? '') : '' ?></td><?php } ?>
<td class="cell-description"><?= $text($description) ?></td>
<td class="cell-narrow"><?= $first ? $e(Format::formatQuantity($line['quantity'])) : '' ?></td>
<td class="cell-number"><?= $first ? $e(Format::formatDecimal($line['unitPrice'])) : '' ?></td>
<?php if ($layout['showDiscount']) { ?><td class="cell-discount"><?= $first && ! empty($line['discount']) ? $e(Format::formatAmount($line['discount'])) : '' ?></td><?php } ?>
<?php if ($layout['showRate']) { ?><td class="cell-narrow"><?= $first && isset($line['vatRate']) ? $e(Format::formatVatRate($line['vatRate'])) : '' ?></td><?php } ?>
<td class="cell-number"><?= $first ? $e(Format::formatAmount($line['amount'])) : '' ?></td>
</tr>
<?php } ?>
<?php } ?>
</tbody>
</table>
<?php
/*
 * C23, C25, L7: the rows above the total, with the aside slot on their left.
 * The total, legends and fiscal footer stay together: when they do not fit,
 * they move to the next sheet as one, so the CAE is never alone. A long tax
 * breakdown flows before them, and only the total stays with the CAE.
 */
$asideHtml = $slots['aside'] ?? '';
$rowCount = count($layout['totalsRows']);
?>
<?php if ($layout['breakTotals']) { ?>
<table class="totals">
<?php foreach ($layout['totalsRows'] as $totalRow) { /* No rowspan: each sheet the rows reach keeps the aside's column. */ ?>
<tr><td class="aside"></td><td class="total-label muted"><?= $e($totalRow['label']) ?></td><td class="total-amount"><?= $money($totalRow['amount']) ?></td></tr>
<?php } ?>
</table>
<?php } ?>
<div class="closing">
<?php if ($layout['breakTotals']) { /* The aside stays beside the total, with the CAE. */ ?>
<table class="totals grand"><tr class="grand-total"><td class="aside"><?= $asideHtml ?></td><td class="total-label b">Importe total</td><td class="total-amount b"><?= $money($doc['totals']['total']) ?></td></tr></table>
<?php } else { ?>
<table class="totals">
<?php foreach ($layout['totalsRows'] as $index => $totalRow) { ?>
<tr><?php if ($index === 0) { ?><td class="aside" rowspan="<?= $rowCount + 1 ?>"><?= $asideHtml ?></td><?php } ?><td class="total-label muted"><?= $e($totalRow['label']) ?></td><td class="total-amount"><?= $money($totalRow['amount']) ?></td></tr>
<?php } ?>
<tr class="grand-total"><?php if ($rowCount === 0) { ?><td class="aside"><?= $asideHtml ?></td><?php } ?><td class="total-label b">Importe total</td><td class="total-amount b"><?= $money($doc['totals']['total']) ?></td></tr>
</table>
<?php } ?>
<?php /* G1 and G3: the conditional legends. */ ?>
<?php if (count($doc['legends']) > 0) { ?>
<div class="legends">
<?php foreach ($doc['legends'] as $legend) { ?><div><?= $e($legend['text']) ?></div><?php } ?>
</div>
<?php } ?>
<div class="closing-space"></div>
</div>
<?php /* L8, G2 and Ley 27.743: bottom left. L9, Q1: CAE, its due date and QR, bottom right, on the last sheet. */ ?>
<div class="footer">
<table class="footer-table"><tr>
<td class="footer-left">
<?php if (! empty($doc['transparency'])) { ?>
<div class="transparency-title"><?= $e($layout['text']['transparencyTitle']) ?></div>
<div class="transparency"><?= $e($layout['text']['vatContained']) ?>: <?= $money($doc['transparency']['vatContained']) ?></div>
<div class="transparency"><?= $e($layout['text']['otherNationalIndirectTaxes']) ?>: <?= $money($doc['transparency']['otherNationalIndirectTaxes']) ?></div>
<?php } ?>
</td>
<td class="footer-right">
<table style="margin-left: auto;"><tr>
<td class="footer-qr"><a href="<?= $e($doc['qr']) ?>"><img src="<?= $e($qr) ?>" alt="QR ARCA"></a></td>
<td class="footer-cae">
<div class="cae"><?= $e($layout['text']['cae']) ?> <?= $e($doc['authorization']['code']) ?></div>
<div class="cae-due-date"><?= $e($layout['text']['caeDueDate']) ?> <?= $e(Format::formatDate($doc['authorization']['dueDate'])) ?></div>
</td>
</tr></table>
</td>
</tr></table>
</div>
<?php } ?>
</body>
</html>
