<?php

declare(strict_types=1);

namespace Clarifile\PiiShield\Learning;

use Clarifile\PiiShield\Detector\DetectorInterface;
use Clarifile\PiiShield\Detector\RegexMatcher;
use Clarifile\PiiShield\Entity;
use Clarifile\PiiShield\EntityType;

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

        // Learned patterns run with a small backtracking budget; exceeding it fails the request (fail closed).
        $previous = \ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '100000');
        try {
            $matches = RegexMatcher::matchAll($regex, $text);
        } finally {
            ini_set('pcre.backtrack_limit', false !== $previous ? $previous : '1000000');
        }

        return array_map(
            fn (array $match): Entity => new Entity(EntityType::Other, $match['value'], $match['offset'], self::CONFIDENCE, self::NAME, $this->type->name),
            $matches,
        );
    }
}
