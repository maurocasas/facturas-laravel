<?php

namespace Facturas\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Vitest's `toMatchObject` for arrays: every key of the expected map must be in
 * the actual one with a matching value, recursively; lists match element by
 * element with the same length. Numbers compare by value (JS has one number
 * type). `MatchObject::containing('x')` is `expect.stringContaining('x')`,
 * `MatchObject::any('string')` is `expect.any(String)`, `MatchObject::anything()`
 * is `expect.anything()`, and `MatchObject::absent()` requires a missing key.
 */
final class MatchObject
{
    public static function assert(mixed $actual, mixed $expected, string $path = '$'): void
    {
        if ($expected instanceof MatchObjectRule) {
            Assert::assertTrue(
                $expected->matches($actual),
                "{$path}: ".json_encode($actual)." does not match {$expected->describe()}",
            );

            return;
        }
        if (is_array($expected)) {
            Assert::assertIsArray($actual, "{$path}: expected an array, got ".json_encode($actual));
            if ($expected !== [] && array_is_list($expected)) {
                Assert::assertTrue(array_is_list($actual), "{$path}: expected a list");
                Assert::assertCount(count($expected), $actual, "{$path}: list length differs");
                foreach ($expected as $index => $item) {
                    self::assert($actual[$index], $item, "{$path}[{$index}]");
                }

                return;
            }
            foreach ($expected as $key => $item) {
                if ($item instanceof MatchObjectRule && $item->absent) {
                    Assert::assertArrayNotHasKey($key, $actual, "{$path}.{$key}: expected absent");

                    continue;
                }
                Assert::assertArrayHasKey($key, $actual, "{$path}.{$key}: missing in ".json_encode($actual));
                self::assert($actual[$key], $item, "{$path}.{$key}");
            }

            return;
        }
        if ((is_int($expected) || is_float($expected)) && (is_int($actual) || is_float($actual))) {
            Assert::assertTrue($expected == $actual, "{$path}: expected {$expected}, got {$actual}");

            return;
        }
        Assert::assertSame($expected, $actual, "{$path}");
    }

    public static function containing(string $text): MatchObjectRule
    {
        return new MatchObjectRule(fn ($value) => is_string($value) && str_contains($value, $text), "a string containing \"{$text}\"");
    }

    public static function any(string $type): MatchObjectRule
    {
        return new MatchObjectRule(fn ($value) => get_debug_type($value) === $type || ($type === 'number' && (is_int($value) || is_float($value))), "any {$type}");
    }

    public static function anything(): MatchObjectRule
    {
        return new MatchObjectRule(fn ($value) => $value !== null, 'anything');
    }

    public static function absent(): MatchObjectRule
    {
        return new MatchObjectRule(fn () => false, 'absent', true);
    }

    /** Fails when any nested array has one of `$keys` (expectNoRaw / noPrivate). */
    public static function assertNoKeys(mixed $value, array $keys, string $path = '$'): void
    {
        if (! is_array($value)) {
            return;
        }
        foreach ($value as $key => $child) {
            Assert::assertNotContains($key, $keys, "{$path} has {$key}");
            self::assertNoKeys($child, $keys, "{$path}.{$key}");
        }
    }
}
