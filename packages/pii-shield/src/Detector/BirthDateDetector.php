<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Detector;

use Briefklar\PiiShield\Entity;
use Briefklar\PiiShield\EntityType;

/**
 * Dates are only personal data when they are birth dates. All other dates
 * (deadlines, letter dates) must survive, so this detector requires a birth keyword.
 */
final class BirthDateDetector implements DetectorInterface
{
    private const string PATTERN = '/(?:\bgeb\.|\bgeboren(?:\s+am)?|\bGeburtsdatum:?)\s*(?<date>\d{1,2}\.\s?\d{1,2}\.\s?\d{4})(?!\d)/iu';

    public function detect(string $text): array
    {
        $entities = [];
        foreach (RegexMatcher::matchAll(self::PATTERN, $text, 'date') as $match) {
            [$day, $month, $year] = array_map(intval(...), explode('.', str_replace(' ', '', $match['value'])));
            if (checkdate($month, $day, $year)) {
                $entities[] = new Entity(EntityType::BirthDate, $match['value'], $match['offset'], 0.95, 'birth_date');
            }
        }

        return $entities;
    }
}
