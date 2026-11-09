<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Tests;

use Briefklar\PiiShield\Entity;
use Briefklar\PiiShield\EntityExtractor;
use Briefklar\PiiShield\EntityType;
use Briefklar\PiiShield\Masking\PartialMask;
use Briefklar\PiiShield\Masking\TypeLabelMask;
use Briefklar\PiiShield\PiiMasker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PiiMaskerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function defaultMaskingProvider(): iterable
    {
        yield 'IBAN keeps first and last 4' => ['IBAN AT61 1904 3002 3457 3201, danke', 'IBAN AT61 **** **** **** 3201, danke'];
        yield 'phone keeps last 2' => ['Mobil: +43 664 1234567', 'Mobil: *** *** *****67'];
        yield 'person as type label' => ['Sehr geehrte Frau Musterfrau,', 'Sehr geehrte Frau [PERSON],'];
        yield 'SVNR as type label' => ['SVNR 4568 150392', 'SVNR [SVNR]'];
        yield 'multi-line address as one label' => ["Frau\nMaria Musterfrau\nLindenweg 12\n6900 Bregenz", "Frau\n[PERSON]\n[ADDRESS]"];
        yield 'no PII unchanged' => ['Betrag 110,00 Euro bis 15.11.2026', 'Betrag 110,00 Euro bis 15.11.2026'];
    }

    #[DataProvider('defaultMaskingProvider')]
    public function testMasksWithDefaultStrategies(string $text, string $expected): void
    {
        self::assertSame($expected, PiiMasker::withDefaultDetectors()->mask($text));
    }

    public function testStrategiesCanBeOverriddenPerType(): void
    {
        $masker = new PiiMasker(EntityExtractor::defaultDetectors(), [EntityType::Iban->value => new TypeLabelMask()]);

        self::assertSame('IBAN [IBAN]', $masker->mask('IBAN AT61 1904 3002 3457 3201'));
    }

    /**
     * @return iterable<string, array{PartialMask, string, string}>
     */
    public static function partialMaskProvider(): iterable
    {
        yield 'keeps whitespace' => [new PartialMask(2, 2), 'AB 12 34 CD', 'AB ** ** CD'];
        yield 'too short is fully masked' => [new PartialMask(4, 4), 'AB12345', '*******'];
        yield 'exactly keep length is fully masked' => [new PartialMask(2, 2), 'ABCD', '****'];
        yield 'multibyte' => [new PartialMask(1, 0), 'Müller', 'M*****'];
        yield 'custom mask character' => [new PartialMask(0, 1, '#'), '12345', '####5'];
    }

    #[DataProvider('partialMaskProvider')]
    public function testPartialMask(PartialMask $strategy, string $value, string $expected): void
    {
        self::assertSame($expected, $strategy->mask(new Entity(EntityType::Iban, $value, 0)));
    }

    public function testTypeLabelFormatIsConfigurable(): void
    {
        $strategy = new TypeLabelMask('<%s>');

        self::assertSame('<PERSON>', $strategy->mask(new Entity(EntityType::Person, 'Maria', 0)));
    }
}
