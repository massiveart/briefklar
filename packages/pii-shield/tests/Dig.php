<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Tests;

use PHPUnit\Framework\Assert;

/**
 * Reads nested decoded JSON/YAML in tests: fails the test with the path instead of assuming a shape.
 */
final class Dig
{
    public static function get(mixed $data, string|int ...$keys): mixed
    {
        $path = '';
        foreach ($keys as $key) {
            $path .= '['.$key.']';
            Assert::assertIsArray($data, \sprintf('Expected an array before %s.', $path));
            Assert::assertArrayHasKey($key, $data, \sprintf('Missing %s.', $path));
            $data = $data[$key];
        }

        return $data;
    }

    /**
     * @return array<mixed>
     */
    public static function array(mixed $data, string|int ...$keys): array
    {
        $value = self::get($data, ...$keys);
        Assert::assertIsArray($value);

        return $value;
    }

    /**
     * A JSON object: an array with string keys only.
     *
     * @return array<string, mixed>
     */
    public static function object(mixed $data, string|int ...$keys): array
    {
        $object = [];
        foreach (self::array($data, ...$keys) as $key => $value) {
            Assert::assertIsString($key, 'Expected an object, got a list.');
            $object[$key] = $value;
        }

        return $object;
    }

    public static function string(mixed $data, string|int ...$keys): string
    {
        $value = self::get($data, ...$keys);
        Assert::assertIsString($value);

        return $value;
    }
}
