<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Detector;

use Briefklar\PiiShield\Entity;
use Briefklar\PiiShield\EntityType;

/**
 * Case and customer references (Aktenzahl, Geschäftszahl, Kundennummer, ...).
 * Only matches after a keyword; the reference must contain a digit.
 */
final class FileNumberDetector implements DetectorInterface
{
    /** Words that introduce a file or customer number; also used to check a model's findings. */
    public const string KEYWORDS = '(?:Zahl|GZ|Gz\.|Geschäftszahl|Aktenzahl|Aktenzeichen|Az\.|Kundennummer|Kunden-Nr\.?|Vertragsnummer|Versicherungsnummer|Verwendungszweck)';
    private const string PATTERN = '/\b'.self::KEYWORDS.'[ \t]*:?[ \t]*(?<ref>(?=[A-Z0-9.\/-]*\d)[A-Z0-9][A-Z0-9.\/-]{2,}[A-Z0-9])(?![\w\/-])/u';

    public function detect(string $text): array
    {
        $entities = [];
        foreach (RegexMatcher::matchAll(self::PATTERN, $text, 'ref') as $match) {
            $entities[] = new Entity(EntityType::FileNumber, $match['value'], $match['offset'], 0.9, 'file_number');
        }

        return $entities;
    }
}
