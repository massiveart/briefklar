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
        $parts = preg_split('/\s[–-]\s/u', $deadline, 2);
        $claim = trim(false === $parts ? $deadline : $parts[0]);
        if ('' === $claim) {
            return false;
        }

        if (1 === preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $claim, $m)) {
            return self::containsDate($letter, (int) $m[1], (int) $m[2], (int) $m[3]);
        }

        return str_contains(self::normalize($letter), self::normalize($claim));
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
