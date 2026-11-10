<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Demo;

use Briefklar\PiiShield\Detector\DetectorInterface;
use Briefklar\PiiShield\Entity;
use Briefklar\PiiShield\EntityType;

/**
 * Demo helper: remembers the findings for the last text (a model is asked once per request,
 * although the demo needs the findings for the list and for redaction) and keeps only the selected types.
 */
final class RequestScopedDetector implements DetectorInterface
{
    private ?string $lastText = null;

    /** @var list<Entity> */
    private array $lastEntities = [];

    /**
     * @param list<EntityType> $types
     */
    public function __construct(
        private readonly DetectorInterface $inner,
        private readonly array $types,
    ) {
    }

    public function detect(string $text): array
    {
        if ($text !== $this->lastText) {
            $this->lastEntities = array_values(array_filter(
                $this->inner->detect($text),
                fn (Entity $e): bool => \in_array($e->type, $this->types, true),
            ));
            $this->lastText = $text;
        }

        return $this->lastEntities;
    }
}
