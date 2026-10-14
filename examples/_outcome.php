<?php

/** Prints each of the four fiscal outcomes the way the SDK examples do. */
function mostrarResultado(array $resultado): void
{
    match ($resultado['kind']) {
        'authorized' => print_r($resultado['voucher']),
        'rejected' => fwrite(STDERR, print_r($resultado['issues'], true)),
        'indeterminate' => fwrite(STDERR, print_r([$resultado['attempted'], $resultado['lookup']], true)),
        'conflict' => fwrite(STDERR, print_r([$resultado['attempted'], $resultado['found']], true)),
    };
}
