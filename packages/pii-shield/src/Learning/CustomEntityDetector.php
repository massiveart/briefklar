<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Learning;

use Briefklar\PiiShield\Detector\DetectorInterface;
use Briefklar\PiiShield\Detector\RegexMatcher;
use Briefklar\PiiShield\Entity;
use Briefklar\PiiShield\EntityType;

/**
 * Finds a learned type by its pattern. Types without a pattern are left to the model detector.
 */
final class CustomEntityDetector implements DetectorInterface
{
    public const string NAME = 'custom';
    private const float CONFIDENCE = 0.75;

    public function __construct(
        private readonly CustomEntityType $type,
    ) {
    }

    public function detect(string $text): array
    {
        $regex = $this->type->regex();
        if (null === $regex) {
            return [];
        }

        return array_map(
            fn (array $match): Entity => new Entity(EntityType::Other, $match['value'], $match['offset'], self::CONFIDENCE, self::NAME, $this->type->name),
            RegexMatcher::matchAll($regex, $text),
        );
    }
}
