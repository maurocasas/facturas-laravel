<?php

namespace Vant\Facturas\Tests\Support;

use PHPUnit\Framework\Assert;

/** Assertions the WSFEX tests share (vitest's `toMatchObject` and friends). */
final class WsfexTestHelpers
{
    /**
     * Recursive subset match, like vitest's `toMatchObject`: every expected key
     * must be present and match; lists must have the same length. A closure as
     * an expected value is a matcher (`expect.any`, `expect.objectContaining`).
     */
    public static function assertSubset(mixed $expected, mixed $actual, string $path = '$'): void
    {
        if ($expected instanceof \Closure) {
            Assert::assertTrue((bool) $expected($actual), "Matcher failed at {$path}: ".var_export($actual, true));

            return;
        }
        if (is_array($expected) && array_is_list($expected) && $expected !== []) {
            Assert::assertIsArray($actual, "Expected a list at {$path}");
            Assert::assertTrue(array_is_list($actual), "Expected a list at {$path}");
            Assert::assertCount(count($expected), $actual, "List length differs at {$path}");
            foreach ($expected as $index => $item) {
                self::assertSubset($item, $actual[$index], "{$path}[{$index}]");
            }

            return;
        }
        if (is_array($expected)) {
            Assert::assertIsArray($actual, "Expected an array at {$path}");
            foreach ($expected as $key => $value) {
                Assert::assertArrayHasKey($key, $actual, "Missing key {$path}.{$key}");
                self::assertSubset($value, $actual[$key], "{$path}.{$key}");
            }

            return;
        }
        if ((is_int($expected) || is_float($expected)) && (is_int($actual) || is_float($actual))) {
            Assert::assertTrue($expected == $actual, "Value differs at {$path}: expected ".var_export($expected, true).', got '.var_export($actual, true));

            return;
        }
        Assert::assertSame($expected, $actual, "Value differs at {$path}");
    }

    /** `expect.objectContaining(...)` as a matcher closure. */
    public static function containing(array $expected): \Closure
    {
        return function (mixed $actual) use ($expected): bool {
            self::assertSubset($expected, $actual);

            return true;
        };
    }

    /** `expect.any(Object)`. */
    public static function anyArray(): \Closure
    {
        return fn (mixed $actual): bool => is_array($actual);
    }

    /** Runs `$fn` and returns what it threw, failing when it throws nothing. */
    public static function thrown(callable $fn): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $error) {
            return $error;
        }
        Assert::fail('Expected an exception, none was thrown.');
    }

    /** The `ArcaInputError` fields `toMatchObject` reads, plus name and code. */
    public static function errorFields(\Throwable $error): array
    {
        $fields = [
            'name' => (new \ReflectionClass($error))->getShortName(),
            'message' => $error->getMessage(),
            'code' => $error->getCode(),
        ];
        foreach ((new \ReflectionObject($error))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isInitialized($error)) {
                $fields[$property->getName()] = $property->getValue($error);
            }
        }

        return $fields;
    }
}
