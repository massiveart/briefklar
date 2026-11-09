<?php

declare(strict_types=1);

namespace Briefklar\PiiShield;

final readonly class RestoreResult
{
    /**
     * @param list<string> $unknownPlaceholders placeholders found in the text that are not in the vault (model invented them)
     * @param list<string> $unusedPlaceholders  vault entries that did not appear in the text (informational)
     */
    public function __construct(
        public string $text,
        public array $unknownPlaceholders = [],
        public array $unusedPlaceholders = [],
    ) {
    }

    public function isClean(): bool
    {
        return [] === $this->unknownPlaceholders;
    }
}
