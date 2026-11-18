<?php

declare(strict_types=1);

namespace App\Tests\Unit\Explanation;

use App\Explanation\DeadlineText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeadlineTextTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, ?string, ?string}>
     */
    public static function cases(): iterable
    {
        yield 'period with source sentence' => ['binnen zwei Wochen ab Zustellung – Bitte überweisen Sie den Betrag binnen zwei Wochen ab Zustellung.', 'binnen zwei Wochen ab Zustellung', 'Bitte überweisen Sie den Betrag binnen zwei Wochen ab Zustellung.', null];
        yield 'period only' => ['binnen vier Wochen', 'binnen vier Wochen', null, null];
        yield 'hyphen as separator' => ['binnen vier Wochen - Zahlen Sie binnen vier Wochen.', 'binnen vier Wochen', 'Zahlen Sie binnen vier Wochen.', null];
        yield 'dash inside the source stays' => ['binnen vier Wochen – Zahlen Sie – wie vereinbart – binnen vier Wochen.', 'binnen vier Wochen', 'Zahlen Sie – wie vereinbart – binnen vier Wochen.', null];
        yield 'iso date' => ['2026-11-30 – Der Betrag ist am 30.11.2026 fällig.', '2026-11-30', 'Der Betrag ist am 30.11.2026 fällig.', '2026-11-30'];
        yield 'iso date only' => ['2027-01-05', '2027-01-05', null, '2027-01-05'];
        yield 'impossible date is no date' => ['2026-02-30 – Bis 30.2.2026.', '2026-02-30', 'Bis 30.2.2026.', null];
        yield 'hyphenated word is no separator' => ['Ende Jänner-Frist', 'Ende Jänner-Frist', null, null];
        yield 'surrounding space' => ['  binnen zwei Wochen   –   Satz.  ', 'binnen zwei Wochen', 'Satz.', null];
    }

    #[DataProvider('cases')]
    public function testSplitsDeadlineAndSourceSentence(string $deadline, string $claim, ?string $source, ?string $date): void
    {
        $text = DeadlineText::parse($deadline);

        self::assertSame($claim, $text->claim);
        self::assertSame($source, $text->source);
        self::assertSame($date, $text->date()?->format('Y-m-d'));
    }
}
