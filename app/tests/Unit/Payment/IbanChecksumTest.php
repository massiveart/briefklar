<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment;

use App\Payment\IbanChecksum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IbanChecksumTest extends TestCase
{
    /**
     * Documentation example IBANs, they belong to nobody.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function candidates(): iterable
    {
        yield 'austrian, grouped' => ['AT61 1904 3002 3457 3201', true];
        yield 'austrian, compact' => ['AT611904300234573201', true];
        yield 'german' => ['DE89 3704 0044 0532 0130 00', true];
        yield 'lower case' => ['at61 1904 3002 3457 3201', true];
        yield 'one digit changed' => ['AT61 1904 3002 3457 3202', false];
        yield 'two digits swapped' => ['AT61 1904 3002 3457 3210', false];
        yield 'too short' => ['AT61 1904', false];
        yield 'too long' => ['AT61 1904 3002 3457 3201 0000 0000 0000 0', false];
        yield 'no country code' => ['6119 0430 0234 5732 01', false];
        yield 'letters in the check digits' => ['ATX1 1904 3002 3457 3201', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('candidates')]
    public function testValidatesTheChecksum(string $candidate, bool $expected): void
    {
        self::assertSame($expected, IbanChecksum::isValid($candidate));
    }

    public function testNormalizesToCompactUpperCase(): void
    {
        self::assertSame('AT611904300234573201', IbanChecksum::normalize(' at61 1904 3002 3457 3201 '));
    }
}
