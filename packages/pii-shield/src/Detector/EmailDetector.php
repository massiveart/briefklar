<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Detector;

use Briefklar\PiiShield\Entity;
use Briefklar\PiiShield\EntityType;

final class EmailDetector implements DetectorInterface
{
    private const string PATTERN = '/(?<![\w.%+-])[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}\b/u';

    public function __construct(
        private readonly bool $keepAuthorityContacts = true,
    ) {
    }

    public function detect(string $text): array
    {
        $entities = [];
        foreach (RegexMatcher::matchAll(self::PATTERN, $text) as $match) {
            if ($this->keepAuthorityContacts && AuthorityContext::isAuthorityContact($text, $match['byteOffset'])) {
                continue;
            }
            $entities[] = new Entity(EntityType::Email, $match['value'], $match['offset'], 1.0, 'email');
        }

        return $entities;
    }
}
