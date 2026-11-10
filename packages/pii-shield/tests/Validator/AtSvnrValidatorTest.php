<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Tests\Validator;

use Briefklar\PiiShield\Validator\AtSvnrValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AtSvnrValidatorTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function validProvider(): iterable
    {
        yield 'fixture 02' => ['4568 150392'];
        yield 'readme example' => ['1237 010180'];
        yield 'without space' => ['1237010180'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'wrong check digit' => ['1234 010180'];
        yield 'check sum 10 is never valid' => ['1000 010180'];
        yield 'leading zero' => ['0237 010180'];
        yield 'too short' => ['1237 01018'];
        yield 'too long' => ['1237 0101801'];
        yield 'letters' => ['12A7 010180'];
        yield 'empty' => [''];
    }

    #[DataProvider('validProvider')]
    public function testAcceptsValidNumbers(string $candidate): void
    {
        self::assertTrue((new AtSvnrValidator())->isValid($candidate));
    }

    #[DataProvider('invalidProvider')]
    public function testRejectsInvalidNumbers(string $candidate): void
    {
        self::assertFalse((new AtSvnrValidator())->isValid($candidate));
    }
}
