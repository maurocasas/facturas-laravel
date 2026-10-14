<?php

namespace Vant\Facturas\Store;

/** One lease backend: acquire, keep alive, and give back only what it owns. */
interface LeaseDriver
{
    public function acquire(string $owner): bool;

    public function renew(string $owner): void;

    public function release(string $owner): void;
}
