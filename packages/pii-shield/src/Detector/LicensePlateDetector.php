<?php

declare(strict_types=1);

namespace Clarifile\PiiShield\Detector;

use Clarifile\PiiShield\Entity;
use Clarifile\PiiShield\EntityType;

/**
 * AT and DE license plates, only after the keyword "Kennzeichen".
 */
final class LicensePlateDetector implements DetectorInterface
{
    private const string PATTERN = '/\bKennzeichen[ \t]*:?[ \t]+(?<plate>[A-ZÄÖÜ]{1,3}[- ](?:[A-Z]{1,2} )?\d{1,6}[A-Z]{0,3})(?![\w-])/u';

    public function detect(string $text): array
    {
        $entities = [];
        foreach (RegexMatcher::matchAll(self::PATTERN, $text, 'plate') as $match) {
            $entities[] = new Entity(EntityType::LicensePlate, $match['value'], $match['offset'], 0.9, 'license_plate');
        }

        return $entities;
    }
}
