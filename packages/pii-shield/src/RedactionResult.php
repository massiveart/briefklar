<?php

declare(strict_types=1);

namespace Briefklar\PiiShield;

/**
 * Result of a redaction. Holds the original values in memory only.
 * Must never be logged, persisted or sent to a model provider.
 */
final readonly class RedactionResult
{
    /**
     * @param array<string, string> $vault    placeholder => original value, e.g. ['⟦PERSON_1⟧' => 'Maria Muster']
     * @param list<Entity>          $entities all detected entities, for diagnostics and tests
     * @param string|null           $vaultId  id of the stored mapping if a vault is configured (docs/adr/0005)
     */
    public function __construct(
        public string $redactedText,
        public array $vault,
        public array $entities,
        public ?string $vaultId = null,
    ) {
    }

    /**
     * @return list<EntityType>
     */
    public function foundTypes(): array
    {
        return array_values(array_unique(array_map(static fn (Entity $e) => $e->type, $this->entities), \SORT_REGULAR));
    }

    public function __debugInfo(): array
    {
        return ['redactedText' => $this->redactedText, 'vault' => '[hidden]', 'entityCount' => \count($this->entities), 'vaultId' => $this->vaultId];
    }
}
