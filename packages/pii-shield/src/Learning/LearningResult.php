<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Learning;

final readonly class LearningResult
{
    /**
     * @param string|null $note why the type has no pattern (model-only), if so
     */
    public function __construct(
        public CustomEntityType $type,
        public ?string $note = null,
    ) {
    }
}
