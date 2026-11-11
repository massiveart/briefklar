<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Tests;

use Briefklar\PiiShield\EntityExtractor;
use Briefklar\PiiShield\PiiMasker;
use Briefklar\PiiShield\PiiShield;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Time must grow linearly with the text: services accept long texts, and a crafted input must not
 * block a worker. Measured as growth, not absolute time, so it holds on slow CI machines:
 * four times the text may take at most eight times as long (quadratic would be sixteen).
 */
final class PerformanceTest extends TestCase
{
    private const int SIZE = 1_000;
    private const int FACTOR = 4;
    private const float MAX_GROWTH = 8.0;

    /**
     * @return iterable<string, array{\Closure(int): string}>
     */
    public static function crafted(): iterable
    {
        yield 'same name repeated' => [static fn (int $n): string => str_repeat('Herr Aaaa ', $n)];
        yield 'different names' => [static fn (int $n): string => implode(' ', array_map(static fn (int $i): string => 'Herr Name'.self::letters($i).' Zeuge', range(1, $n)))];
        yield 'full names in address blocks' => [static fn (int $n): string => implode("\n\n", array_map(static fn (int $i): string => "Frau\nMaria Name".self::letters($i)."\nLindenweg ".($i % 999 + 1)."\n6900 Bregenz", range(1, $n)))];
        yield 'e-mails and phone numbers' => [static fn (int $n): string => implode("\n", array_map(static fn (int $i): string => "Kontakt: person{$i}@example.org, Tel. +43 660 ".(1_000_000 + $i), range(1, $n)))];
        yield 'many IBANs' => [static fn (int $n): string => str_repeat('IBAN AT61 1904 3002 3457 3201, ', $n)];
    }

    /**
     * @param \Closure(int): string $text
     */
    #[DataProvider('crafted')]
    public function testRedactionGrowsLinearly(\Closure $text): void
    {
        $shield = PiiShield::withDefaultDetectors();

        self::assertLinear(static fn (string $t): mixed => $shield->redact($t), $text);
    }

    /**
     * @param \Closure(int): string $text
     */
    #[DataProvider('crafted')]
    public function testMaskingGrowsLinearly(\Closure $text): void
    {
        $masker = new PiiMasker(EntityExtractor::defaultDetectors());

        self::assertLinear(static fn (string $t): mixed => $masker->mask($t), $text);
    }

    /**
     * @param \Closure(string): mixed $operation
     * @param \Closure(int): string   $text
     */
    private static function assertLinear(\Closure $operation, \Closure $text): void
    {
        $small = self::fastest($operation, $text(self::SIZE));
        $large = self::fastest($operation, $text(self::SIZE * self::FACTOR));

        self::assertLessThan(self::MAX_GROWTH, $large / $small, \sprintf('%.1f ms → %.1f ms for %d× the text', $small / 1e6, $large / 1e6, self::FACTOR));
    }

    /**
     * Best of three runs in nanoseconds (less noise than a single run).
     *
     * @param \Closure(string): mixed $operation
     */
    private static function fastest(\Closure $operation, string $text): float
    {
        $best = \PHP_FLOAT_MAX;
        for ($run = 0; $run < 3; ++$run) {
            $start = hrtime(true);
            $operation($text);
            $best = min($best, (float) (hrtime(true) - $start));
        }

        return $best;
    }

    /** "Ab", "Ac", ...: distinct letter-only suffixes (names contain no digits). */
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
