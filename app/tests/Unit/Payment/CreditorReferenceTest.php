<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment;

use App\Payment\CreditorReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CreditorReferenceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function candidates(): iterable
    {
        // From the EPC069-12 example.
        yield 'valid' => ['RF18539007547034', true];
        yield 'valid, grouped' => ['RF18 5390 0754 7034', true];
        yield 'check digits wrong' => ['RF19539007547034', false];
        yield 'not an RF reference' => ['BHMS-VK-2026/48213', false];
        yield 'too long' => ['RF18'.str_repeat('1', 22), false];
    }

    #[DataProvider('candidates')]
    public function testValidatesTheChecksum(string $candidate, bool $expected): void
    {
        self::assertSame($expected, CreditorReference::isValid($candidate));
    }
}
