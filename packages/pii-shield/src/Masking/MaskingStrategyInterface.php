<?php

declare(strict_types=1);

namespace Clarifile\PiiShield\Masking;

use Clarifile\PiiShield\Entity;

interface MaskingStrategyInterface
{
    /**
     * Irreversible replacement text for the entity.
     */
    public function mask(Entity $entity): string;
}
