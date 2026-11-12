<?php

declare(strict_types=1);

namespace App\Tests\Unit\Explanation;

use App\Explanation\ActionAdvice;
use App\Explanation\LetterExplanation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ActionAdviceTest extends TestCase
{
    /**
     * @return iterable<string, array{bool, list<string>, ?string, ?string, ActionAdvice}>
     */
    public static function answers(): iterable
    {
        yield 'steps and required' => [true, ['Bezahle 36,00 Euro.'], '36,00 Euro', null, ActionAdvice::Steps];
        // Seen with qwen3:8b on letter 02 in Leichte Sprache.
        yield 'steps but not required' => [false, ['Überweise 1.245,00 Euro.'], '1.245,00 Euro', null, ActionAdvice::Steps];
        yield 'required without steps' => [true, [], null, null, ActionAdvice::Unclear];
        yield 'amount without steps' => [false, [], '1.245,00 Euro', null, ActionAdvice::Unclear];
        yield 'deadline without steps' => [false, [], null, '2026-11-15 – Der Betrag ist am 15.11.2026 fällig.', ActionAdvice::Unclear];
        yield 'really nothing to do' => [false, [], null, null, ActionAdvice::NothingToDo];
    }

    /**
     * @param list<string> $actions
     */
    #[DataProvider('answers')]
    public function testOnlySaysNothingToDoWhenEveryFieldAgrees(bool $actionRequired, array $actions, ?string $amount, ?string $deadline, ActionAdvice $expected): void
    {
        $explanation = new LetterExplanation('Finanzamt Österreich', 'Buchungsmitteilung', 'Zusammenfassung.', $actionRequired, $actions, $deadline, $amount, [], []);

        self::assertSame($expected, ActionAdvice::of($explanation));
    }
}
