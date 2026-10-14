<?php

namespace Vant\Facturas\Wsaa;

/** A WSAA session store that serializes concurrent ticket renewals. */
interface LockingWsaaSessionStore extends WsaaSessionStore
{
    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public function withLock(array $key, callable $fn): mixed;
}
