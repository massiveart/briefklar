<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Detector;

use Briefklar\PiiShield\Entity;
use Briefklar\PiiShield\EntityType;

/**
 * International (+43 ..., 0049 ...) and national (0664/...) phone numbers.
 * Requires at least 7 digits; dates and times do not match because "." and ":" are not separators.
 */
final class PhoneDetector implements DetectorInterface
{
    private const string PATTERN = '/(?<![\w+\/])(?:(?:\+|00)\d{1,3}|0\d{1,4})(?:[ \/-]?\d{2,}){1,5}(?![\w\/])/u';
    private const int MIN_DIGITS = 7;

    public function __construct(
        private readonly bool $keepAuthorityContacts = true,
    ) {
    }

    public function detect(string $text): array
    {
        $entities = [];
        foreach (RegexMatcher::matchAll(self::PATTERN, $text) as $match) {
            if (\strlen((string) preg_replace('/\D/', '', $match['value'])) < self::MIN_DIGITS) {
                continue;
            }
            if ($this->keepAuthorityContacts && AuthorityContext::isAuthorityContact($text, $match['byteOffset'])) {
                continue;
            }
            $entities[] = new Entity(EntityType::Phone, $match['value'], $match['offset'], 0.8, 'phone');
        }

        return $entities;
    }
}
