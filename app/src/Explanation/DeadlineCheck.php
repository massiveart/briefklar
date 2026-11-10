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
    private const array MONTHS = [
        1 => ['januar', 'jänner'], 2 => ['februar', 'feber'], 3 => ['märz'], 4 => ['april'], 5 => ['mai'], 6 => ['juni'],
        7 => ['juli'], 8 => ['august'], 9 => ['september'], 10 => ['oktober'], 11 => ['november'], 12 => ['dezember'],
    ];

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
        if (!checkdate($month, $day, $year) || str_contains($letter, \sprintf('%04d-%02d-%02d', $year, $month, $day))) {
            return checkdate($month, $day, $year);
        }

        $numeric = \sprintf('/(?<!\d)0?%d\.\s?0?%d\.\s?%d(?!\d)/u', $day, $month, $year);
        $written = \sprintf('/(?<!\d)0?%d\.\s*(?:%s)\s+%d(?!\d)/iu', $day, implode('|', self::MONTHS[$month]), $year);

        return 1 === preg_match($numeric, $letter) || 1 === preg_match($written, $letter);
    }

    private static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($text)));
    }
}
