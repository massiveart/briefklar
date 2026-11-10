<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Learning;

/**
 * Where learned entity types live. Default: JsonFileCustomEntityStore; any PSR-6 pool via CacheCustomEntityStore.
 */
interface CustomEntityStoreInterface
{
    /**
     * @return list<CustomEntityType> sorted by name
     */
    public function all(): array;

    public function get(string $name): ?CustomEntityType;

    /**
     * Adds the type or replaces the one with the same name.
     */
    public function save(CustomEntityType $type): void;

    public function remove(string $name): void;
}
