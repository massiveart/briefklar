<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Detector;

use Briefklar\PiiShield\Entity;

interface DetectorInterface
{
    /**
     * @return list<Entity>
     */
    public function detect(string $text): array;
}
