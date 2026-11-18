<?php

declare(strict_types=1);

namespace App\Tests\Unit\Explanation;

use App\Explanation\DeadlineCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeadlineCheckTest extends TestCase
{
    private const string LETTER = <<<'TXT'
        Musterstadt, 28.10.2026
        Bitte überweisen Sie den Betrag binnen zwei Wochen ab Zustellung.
        Die Unterlagen sind bis 5.1.2027 vorzulegen. Spätestens am 14. November 2026 endet die Frist.
        TXT;

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function cases(): iterable
    {
        yield 'period verbatim' => ['binnen zwei Wochen ab Zustellung – Bitte überweisen Sie ...', true];
        yield 'period with other case and spacing' => ['Binnen  zwei Wochen ab Zustellung', true];
        yield 'iso date written as dd.mm.yyyy' => ['2026-10-28 – Musterstadt, 28.10.2026', true];
        yield 'iso date written without leading zeros' => ['2027-01-05 – Die Unterlagen sind bis 5.1.2027 vorzulegen.', true];
        yield 'iso date written with month name' => ['2026-11-14 – Spätestens am 14. November 2026 endet die Frist.', true];
        yield 'invented date' => ['2026-11-11 – binnen zwei Wochen ab Zustellung', false];
        yield 'computed date from period' => ['2026-11-25', false];
        yield 'invented period' => ['binnen vier Wochen', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('cases')]
    public function testDeadlineMustBeStatedInLetter(string $deadline, bool $expected): void
    {
        self::assertSame($expected, (new DeadlineCheck())->isStatedInLetter($deadline, self::LETTER));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function sources(): iterable
    {
        yield 'verbatim' => ['binnen zwei Wochen ab Zustellung – Bitte überweisen Sie den Betrag binnen zwei Wochen ab Zustellung.', true];
        yield 'part of a sentence, own full stop' => ['binnen zwei Wochen ab Zustellung – Bitte überweisen Sie den Betrag.', true];
        yield 'in quotes' => ['binnen zwei Wochen ab Zustellung – „Bitte überweisen Sie den Betrag binnen zwei Wochen ab Zustellung.“', true];
        yield 'other case and line break' => ["2027-01-05 – die Unterlagen sind bis 5.1.2027\nvorzulegen", true];
        yield 'shortened with ellipsis' => ['binnen zwei Wochen ab Zustellung – Bitte überweisen Sie … ab Zustellung.', true];
        yield 'shortened with three dots' => ['binnen zwei Wochen ab Zustellung – Bitte überweisen Sie ...', true];
        yield 'reworded into the du-form' => ['binnen zwei Wochen ab Zustellung – Bitte überweise den Betrag binnen zwei Wochen ab Zustellung.', false];
        yield 'ellipsis parts in the wrong order' => ['binnen zwei Wochen ab Zustellung – ab Zustellung … Bitte überweisen Sie', false];
        yield 'only an ellipsis' => ['binnen zwei Wochen ab Zustellung – …', false];
        yield 'no source' => ['binnen zwei Wochen ab Zustellung', false];
    }

    #[DataProvider('sources')]
    public function testSourceMustBeQuotedFromLetter(string $deadline, bool $expected): void
    {
        self::assertSame($expected, (new DeadlineCheck())->isSourceQuotedFromLetter($deadline, self::LETTER));
    }
}
