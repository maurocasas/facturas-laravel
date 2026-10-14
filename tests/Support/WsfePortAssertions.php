<?php

namespace Facturas\Tests\Support;

use PHPUnit\Framework\Assert;
use Facturas\Errors\ArcaError;

/**
 * vitest-style assertions for the ported WSFE tests:
 * `toMatchObject` (recursive subset) and `toThrowError(expect.objectContaining(...))`.
 */
final class WsfePortAssertions
{
    /** Recursive subset match: maps match by key, lists element by element with equal length. */
    public static function assertSubset(mixed $expected, mixed $actual, string $path = '$'): void
    {
        if (is_array($expected) && $expected !== [] && ! array_is_list($expected)) {
            Assert::assertIsArray($actual, "{$path} is not an object");
            foreach ($expected as $key => $value) {
                Assert::assertArrayHasKey($key, $actual, "{$path}.{$key} is missing");
                self::assertSubset($value, $actual[$key], "{$path}.{$key}");
            }

            return;
        }
        if (is_array($expected)) {
            Assert::assertIsArray($actual, "{$path} is not an array");
            Assert::assertCount(count($expected), $actual, "{$path} has a different length");
            foreach (array_values($expected) as $index => $value) {
                self::assertSubset($value, array_values($actual)[$index], "{$path}[{$index}]");
            }

            return;
        }
        if ((is_int($expected) || is_float($expected)) && (is_int($actual) || is_float($actual))) {
            Assert::assertEquals($expected, $actual, "{$path} differs");

            return;
        }
        Assert::assertSame($expected, $actual, "{$path} differs");
    }

    /** Runs `$fn`, asserts it throws and that the error matches the given properties. */
    public static function assertThrowsMatching(callable $fn, array $properties): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $error) {
            self::assertErrorMatches($properties, $error);

            return $error;
        }
        Assert::fail('Expected an error to be thrown');
    }

    /** `expect(error).toMatchObject({name, code, message, field, ...})`. */
    public static function assertErrorMatches(array $properties, \Throwable $error): void
    {
        foreach ($properties as $key => $value) {
            $actual = match ($key) {
                'name' => (new \ReflectionClass($error))->getShortName(),
                'code' => $error instanceof ArcaError ? $error->errorCode() : $error->getCode(),
                'message' => $error->getMessage(),
                default => $error->{$key},
            };
            self::assertSubset($value, $actual, "error.{$key}");
        }
    }
}
