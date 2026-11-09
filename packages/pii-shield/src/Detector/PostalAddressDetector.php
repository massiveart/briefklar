<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Detector;

use Briefklar\PiiShield\Entity;
use Briefklar\PiiShield\EntityType;

/**
 * Two-line postal address as found in the recipient block:
 * "Street 12[/3]" followed by "AT 4-digit or DE 5-digit postcode + city".
 *
 * Single-line addresses ("Hauptplatz 1, 6999 Musterstadt") are typically the sender's
 * letterhead and are kept. Street names without house number are left to NER.
 */
final class PostalAddressDetector implements DetectorInterface
{
    private const string PATTERN = "/^(?<address>[A-ZÄÖÜ][\\p{L}.' -]*?[ \\t]\\d{1,4}[a-zA-Z]?(?:\\/\\d{1,4}[a-zA-Z]?)*[ \\t]*\\n(?:(?:A|D|CH)-)?(?:[1-9]\\d{3}|\\d{5})[ \\t]+[A-ZÄÖÜ][\\p{L}.' -]*\\p{L})[ \\t]*$/mu";

    public function detect(string $text): array
    {
        $entities = [];
        foreach (RegexMatcher::matchAll(self::PATTERN, $text, 'address') as $match) {
            $entities[] = new Entity(EntityType::Address, $match['value'], $match['offset'], 0.8, 'postal_address');
        }

        return $entities;
    }
}
