<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Masking;

use Briefklar\PiiShield\Entity;

/**
 * Replaces the whole value with its type: "Maria Musterfrau" -> "[PERSON]".
 */
final class TypeLabelMask implements MaskingStrategyInterface
{
    /**
     * @param string $format sprintf format, receives the entity type value (e.g. "PERSON")
     */
    public function __construct(
        private readonly string $format = '[%s]',
    ) {
    }

    public function mask(Entity $entity): string
    {
        return \sprintf($this->format, $entity->type->value);
    }
}
