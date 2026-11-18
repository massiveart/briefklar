<?php

declare(strict_types=1);

namespace Clarifile\PiiShield\Detector;

use Clarifile\PiiShield\Entity;

interface DetectorInterface
{
    /**
     * @return list<Entity>
     */
    public function detect(string $text): array;
}
