<?php

declare(strict_types=1);

namespace App\Explanation;

/**
 * Guards "never invent dates" in the free text of an explanation (subject, summary, actions,
 * questionsToAsk): models compute due dates from periods ("binnen zwei Wochen ab Zustellung" →
 * "bis 12.11.2026") although the prompts forbid it. The deadline field has its own DeadlineCheck.
 *
 * A date counts as stated if the letter contains the same day in any recognised format.
 */
final readonly class ProseDateCheck
{
    /**
     * @return list<string> dates as written in the explanation that do not appear in the letter, each once
     */
    public function datesNotInLetter(LetterExplanation $explanation, string $letter): array
    {
        $inLetter = array_column(CalendarDates::in($letter), 'iso', 'iso');
        $prose = implode("\n", [$explanation->subject, $explanation->summary, ...$explanation->actions, ...$explanation->questionsToAsk]);

        $invented = [];
        foreach (CalendarDates::in($prose) as $date) {
            if (!isset($inLetter[$date['iso']])) {
                $invented[$date['iso']] ??= $date['written'];
            }
        }

        return array_values($invented);
    }
}
