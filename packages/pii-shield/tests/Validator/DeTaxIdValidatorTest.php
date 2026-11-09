<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Tests\Validator;

use Briefklar\PiiShield\Validator\DeTaxIdValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeTaxIdValidatorTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function validProvider(): iterable
    {
        yield 'one digit twice' => ['86095742719'];
        yield 'one digit three times' => ['65929970489'];
        yield 'grouped with spaces' => ['86 095 742 719'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'wrong check digit' => ['86095742718'];
        yield 'leading zero' => ['06095742719'];
        yield 'no repeated digit' => ['12345678903'];
        yield 'too short' => ['8609574271'];
        yield 'letters' => ['8609574271A'];
        yield 'empty' => [''];
    }

    #[DataProvider('validProvider')]
    public function testAcceptsValidTaxIds(string $candidate): void
    {
        self::assertTrue((new DeTaxIdValidator())->isValid($candidate));
    }

    #[DataProvider('invalidProvider')]
    public function testRejectsInvalidTaxIds(string $candidate): void
    {
        self::assertFalse((new DeTaxIdValidator())->isValid($candidate));
    }
}
