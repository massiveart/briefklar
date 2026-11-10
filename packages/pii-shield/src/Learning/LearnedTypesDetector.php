<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Learning;

use Briefklar\PiiShield\Detector\DetectorInterface;

/**
 * Runs the patterns of all currently learned types; new types take effect on the next text.
 */
final class LearnedTypesDetector implements DetectorInterface
{
    public function __construct(
        private readonly CustomEntityStoreInterface $store,
    ) {
    }

    public function detect(string $text): array
    {
        $entities = [];
        foreach ($this->store->all() as $type) {
            foreach ((new CustomEntityDetector($type))->detect($text) as $entity) {
                $entities[] = $entity;
            }
        }

        return $entities;
    }
}
