<?php

namespace Facturas\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Facturas\FacturasServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [FacturasServiceProvider::class];
    }
}
