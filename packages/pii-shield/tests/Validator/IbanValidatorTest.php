<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Tests\Validator;

use Briefklar\PiiShield\Validator\IbanValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IbanValidatorTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function validProvider(): iterable
    {
        yield 'AT grouped (fixture 01)' => ['AT61 1904 3002 3457 3201'];
        yield 'AT compact' => ['AT611904300234573201'];
        yield 'DE' => ['DE89 3704 0044 0532 0130 00'];
        yield 'CH' => ['CH93 0076 2011 6238 5295 7'];
        yield 'lowercase' => ['at61 1904 3002 3457 3201'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'wrong check digits' => ['AT62 1904 3002 3457 3201'];
        yield 'typo in account' => ['AT61 1904 3002 3457 3202'];
        yield 'too short' => ['AT61 1904'];
        yield 'no country code' => ['6119 0430 0234 5732 01'];
        yield 'special characters' => ['AT61-1904-3002-3457-3201'];
        yield 'empty' => [''];
    }

    #[DataProvider('validProvider')]
    public function testAcceptsValidIbans(string $candidate): void
    {
        self::assertTrue((new IbanValidator())->isValid($candidate));
    }

    #[DataProvider('invalidProvider')]
    public function testRejectsInvalidIbans(string $candidate): void
    {
        self::assertFalse((new IbanValidator())->isValid($candidate));
    }
}
