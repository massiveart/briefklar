<?php

declare(strict_types=1);

namespace Briefklar\PiiShield;

/**
 * A detected span of personal data in the original text.
 * Learned types use EntityType::Other with their own label (e.g. "INSURANCE_NUMBER").
 */
final readonly class Entity
{
    public function __construct(
        public EntityType $type,
        public string $value,
        public int $offset,
        public float $confidence = 1.0,
        public string $detector = 'unknown',
        public ?string $label = null,
    ) {
    }

    /**
     * Name used in placeholders and masks: the custom label of a learned type, otherwise the type.
     */
    public function typeName(): string
    {
        return $this->label ?? $this->type->value;
    }

    public function length(): int
    {
        return mb_strlen($this->value);
    }
}
