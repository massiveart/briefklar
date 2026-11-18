<?php

declare(strict_types=1);

namespace Clarifile\PiiShield\Tests\Bridge\Symfony\Fixtures;

use Clarifile\PiiShield\Learning\CustomEntityStoreInterface;
use Clarifile\PiiShield\Learning\CustomEntityType;

/**
 * Stands in for an application's own store of learned entity types in the bundle tests.
 */
final class InMemoryCustomEntityStore implements CustomEntityStoreInterface
{
    /** @var array<string, CustomEntityType> */
    private array $types = [];

    public function all(): array
    {
        $types = $this->types;
        ksort($types);

        return array_values($types);
    }

    public function get(string $name): ?CustomEntityType
    {
        return $this->types[$name] ?? null;
    }

    public function save(CustomEntityType $type): void
    {
        $this->types[$type->name] = $type;
    }

    public function remove(string $name): void
    {
        unset($this->types[$name]);
    }
}
