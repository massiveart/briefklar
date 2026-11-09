<?php

declare(strict_types=1);

namespace Briefklar\PiiShield;

/**
 * A detected span of personal data in the original text.
 */
final readonly class Entity
{
    public function __construct(
        public EntityType $type,
        public string $value,
        public int $offset,
        public float $confidence = 1.0,
        public string $detector = 'unknown',
    ) {
    }

    public function length(): int
    {
        return mb_strlen($this->value);
    }
}
