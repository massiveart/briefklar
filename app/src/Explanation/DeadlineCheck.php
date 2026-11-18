<?php

declare(strict_types=1);

namespace App\Explanation;

/**
 * Guards the core promise "never invent deadlines": a deadline from the model counts only if it
 * is written in the letter, either verbatim (a period like "binnen zwei Wochen ab Zustellung")
 * or as an ISO date that appears in the letter in a German date format.
 */
final readonly class DeadlineCheck
{
    public function isStatedInLetter(string $deadline, string $letter): bool
    {
        // The model reports "<deadline> – <source sentence>"; only the deadline part is checked.
        $claim = DeadlineText::parse($deadline)->claim;
        if ('' === $claim) {
            return false;
        }

        if (1 === preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $claim, $m)) {
            return self::containsDate($letter, (int) $m[1], (int) $m[2], (int) $m[3]);
        }

        return str_contains(self::normalize($letter), self::normalize($claim));
    }

    /**
     * Whether the sentence the model gives as the source of the deadline is a quote from the letter,
     * so the result page may show it as "In the letter". Models sometimes reword it (issue #78), for
     * example into the du-form; then it is no quote. Tolerated: case, whitespace and line breaks,
     * surrounding quotes, a full stop of its own and shortening with "…" (the parts in order).
     */
    public function isSourceQuotedFromLetter(string $deadline, string $letter): bool
    {
        $source = DeadlineText::parse($deadline)->source;
        if (null === $source) {
            return false;
        }

        $pieces = preg_split('/…|\.{3}/u', $source);
        if (false === $pieces) {
            return false;
        }
        $parts = array_values(array_filter(
            array_map(
                static fn (string $part): string => self::normalize(mb_trim($part, " \t\n\r\"'„“”‚‘’»«.,;:")),
                $pieces,
            ),
            static fn (string $part): bool => '' !== $part,
        ));
        if ([] === $parts) {
            return false;
        }

        $text = self::normalize($letter);
        $offset = 0;
        foreach ($parts as $part) {
            $at = mb_strpos($text, $part, $offset);
            if (false === $at) {
                return false;
            }
            $offset = $at + mb_strlen($part);
        }

        return true;
    }

    private static function containsDate(string $letter, int $year, int $month, int $day): bool
    {
        $iso = \sprintf('%04d-%02d-%02d', $year, $month, $day);

        return checkdate($month, $day, $year)
            && array_any(CalendarDates::in($letter), static fn (array $date): bool => $iso === $date['iso']);
    }

    private static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($text)));
    }
}
