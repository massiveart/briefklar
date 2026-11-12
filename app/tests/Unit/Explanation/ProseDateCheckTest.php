<?php

declare(strict_types=1);

namespace App\Tests\Unit\Explanation;

use App\Explanation\LetterExplanation;
use App\Explanation\ProseDateCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProseDateCheckTest extends TestCase
{
    private const string LETTER = <<<'TXT'
        Musterstadt, 28.10.2026
        Bitte überweisen Sie den Betrag binnen zwei Wochen ab Zustellung.
        Die Unterlagen sind bis 5.1.2027 vorzulegen. Spätestens am 14. November 2026 endet die Frist.
        Tatzeit: 2026-10-14
        TXT;

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function cases(): iterable
    {
        yield 'no date' => ['Zahle 110,00 Euro binnen zwei Wochen ab Zustellung.', []];
        yield 'date as in letter' => ['Der Brief ist vom 28.10.2026.', []];
        yield 'date with leading zeros added' => ['Lege die Unterlagen bis 05.01.2027 vor.', []];
        yield 'date with spaces' => ['Lege die Unterlagen bis 5. 1. 2027 vor.', []];
        yield 'numeric date written with month name in letter' => ['Die Frist endet am 14.11.2026.', []];
        yield 'month name for numeric date in letter' => ['Der Brief ist vom 28. Oktober 2026.', []];
        yield 'Austrian month name' => ['Lege die Unterlagen bis 5. Jänner 2027 vor.', []];
        yield 'ISO date of a letter date' => ['Deadline: 2027-01-05.', []];
        yield 'German date of an ISO date in the letter' => ['Du warst am 14.10.2026 zu schnell.', []];
        yield 'English month name' => ['The period ends on 14 November 2026.', []];
        yield 'English month first' => ['The period ends on November 14, 2026.', []];

        yield 'computed date (qwen3:8b, 12.11.2026)' => ['Bezahle 110,00 Euro bis 12.11.2026 auf das Konto.', ['12.11.2026']];
        yield 'invented date with month name' => ['Zahle bis 30. November 2026.', ['30. November 2026']];
        yield 'invented ISO date' => ['Deadline: 2026-11-30.', ['2026-11-30']];
        yield 'invented English date' => ['Pay by 30 November 2026.', ['30 November 2026']];
        yield 'same day, other year' => ['Der Brief ist vom 28.10.2025.', ['28.10.2025']];
        yield 'reported once' => ['Bis 30.11.2026, also spätestens 30.11.2026.', ['30.11.2026']];
        yield 'part of a longer number is no date' => ['Aktenzahl 128.10.20261', []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('cases')]
    public function testReportsDatesThatAreNotInTheLetter(string $summary, array $expected): void
    {
        self::assertSame($expected, (new ProseDateCheck())->datesNotInLetter(self::explanation(summary: $summary), self::LETTER));
    }

    public function testChecksSubjectSummaryActionsAndQuestionsInThatOrder(): void
    {
        $explanation = self::explanation(
            subject: 'Brief vom 3.12.2026',
            summary: 'Am 1.12.2026 wird es teurer.',
            actions: ['Zahle bis 30.11.2026.'],
            questions: ['Gilt der 2.12.2026 noch?'],
        );

        self::assertSame(['3.12.2026', '1.12.2026', '30.11.2026', '2.12.2026'], (new ProseDateCheck())->datesNotInLetter($explanation, self::LETTER));
    }

    public function testIgnoresTheCheckedFields(): void
    {
        // The deadline field has its own check (DeadlineCheck), the sender is copied from the letter.
        $explanation = new LetterExplanation('Amt vom 1.1.2020', 'Betreff', 'Kein Datum.', false, [], '2026-11-30 – erfunden', null, [], []);

        self::assertSame([], (new ProseDateCheck())->datesNotInLetter($explanation, self::LETTER));
    }

    /**
     * @param list<string> $actions
     * @param list<string> $questions
     */
    private static function explanation(string $summary, array $actions = [], array $questions = [], string $subject = 'Betreff'): LetterExplanation
    {
        return new LetterExplanation('Amt', $subject, $summary, [] !== $actions, $actions, null, null, [], $questions);
    }
}
