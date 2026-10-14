<?php

namespace Facturas\Tests\Support;

final class MatchObjectRule
{
    /** @param \Closure(mixed): bool $test */
    public function __construct(private readonly \Closure $test, private readonly string $description, public readonly bool $absent = false) {}

    public function matches(mixed $value): bool
    {
        return ($this->test)($value);
    }

    public function describe(): string
    {
        return $this->description;
    }
}
