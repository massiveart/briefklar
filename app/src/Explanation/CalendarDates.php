<?php

declare(strict_types=1);

namespace App\Explanation;

/**
 * Finds calendar dates in German or English text: "28.10.2026", "5. 1. 2027", "14. November 2026",
 * "5. Jänner 2027", "14 November 2026", "November 14, 2026" and ISO "2026-10-28".
 *
 * Dates without a year ("bis 12.11.") are not recognised: too easily confused with other numbers.
 */
final class CalendarDates
{
    private const array MONTHS = [
        'januar' => 1, 'jänner' => 1, 'january' => 1, 'februar' => 2, 'feber' => 2, 'february' => 2,
        'märz' => 3, 'march' => 3, 'april' => 4, 'mai' => 5, 'may' => 5, 'juni' => 6, 'june' => 6,
        'juli' => 7, 'july' => 7, 'august' => 8, 'september' => 9, 'oktober' => 10, 'october' => 10,
        'november' => 11, 'dezember' => 12, 'december' => 12,
    ];

    /**
     * @return list<array{iso: string, written: string}> in order of appearance; iso is "YYYY-MM-DD", not validated
     */
    public static function in(string $text): array
    {
        $months = implode('|', array_keys(self::MONTHS));
        $patterns = [
            '/(?<![\d.])(?<d>\d{1,2})\.\s?(?<m>\d{1,2})\.\s?(?<y>\d{4})(?!\d)/u',
            '/(?<!\d)(?<d>\d{1,2})\.?\s*(?<month>'.$months.')\s+(?<y>\d{4})(?!\d)/iu',
            '/\b(?<month>'.$months.')\s+(?<d>\d{1,2}),?\s+(?<y>\d{4})(?!\d)/iu',
            '/(?<!\d)(?<y>\d{4})-(?<m>\d{2})-(?<d>\d{2})(?!\d)/u',
        ];

        $found = [];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $text, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
            foreach ($matches as $match) {
                // Only the patterns with a month name have a "month" group.
                $month = isset($match['month'])
                    ? self::MONTHS[mb_strtolower($match['month'][0])]
                    : (int) $match['m'][0];
                $found[$match[0][1]] = [
                    'iso' => \sprintf('%04d-%02d-%02d', (int) $match['y'][0], $month, (int) $match['d'][0]),
                    'written' => $match[0][0],
                ];
            }
        }
        ksort($found);

        return array_values($found);
    }
}
