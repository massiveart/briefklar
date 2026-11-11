<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Tests;

use Briefklar\PiiShield\PiiShield;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Redaction time must grow linearly with the text: services accept long texts, and a crafted
 * input must not block a worker. A typical letter takes well under 1 ms; the bound is generous.
 */
final class PerformanceTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function crafted(): iterable
    {
        yield 'same name 5000 times' => [str_repeat('Herr Aaaa ', 5_000)];
        yield '5000 different names' => [implode(' ', array_map(static fn (int $i): string => 'Herr Name'.self::letters($i).' Zeuge', range(1, 5_000)))];
        yield '5000 e-mails and phone numbers' => [implode("\n", array_map(static fn (int $i): string => "Kontakt: person{$i}@example.org, Tel. +43 660 ".(1_000_000 + $i), range(1, 5_000)))];
        yield 'mask 5000 values' => [str_repeat('IBAN AT61 1904 3002 3457 3201, ', 5_000)];
        yield '5000 full names in address blocks' => [implode("\n\n", array_map(static fn (int $i): string => "Frau\nMaria Name".self::letters($i)."\nLindenweg ".($i % 999 + 1)."\n6900 Bregenz", range(1, 2_000)))];
    }

    #[DataProvider('crafted')]
    public function testCraftedLongTextsAreRedactedInLinearTime(string $text): void
    {
        $shield = PiiShield::withDefaultDetectors();

        $start = hrtime(true);
        $shield->redact($text);
        $ms = (hrtime(true) - $start) / 1e6;

        self::assertLessThan(500, $ms, \sprintf('%d characters took %.0f ms', mb_strlen($text), $ms));
    }

    #[DataProvider('crafted')]
    public function testCraftedLongTextsAreMaskedInLinearTime(string $text): void
    {
        $masker = new \Briefklar\PiiShield\PiiMasker(\Briefklar\PiiShield\EntityExtractor::defaultDetectors());

        $start = hrtime(true);
        $masker->mask($text);
        $ms = (hrtime(true) - $start) / 1e6;

        self::assertLessThan(500, $ms, \sprintf('%d characters took %.0f ms', mb_strlen($text), $ms));
    }

    /** "Aab", "Aac", ...: distinct letter-only suffixes (names contain no digits). */
    private static function letters(int $i): string
    {
        $s = '';
        do {
            $s = \chr(97 + $i % 26).$s;
            $i = intdiv($i, 26);
        } while ($i > 0);

        return ucfirst('a'.$s);
    }
}
