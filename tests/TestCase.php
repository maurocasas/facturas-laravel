<?php

namespace Vant\Facturas\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Vant\Facturas\FacturasServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [FacturasServiceProvider::class];
    }
}
