<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Masking;

use Briefklar\PiiShield\Entity;

interface MaskingStrategyInterface
{
    /**
     * Irreversible replacement text for the entity.
     */
    public function mask(Entity $entity): string;
}
