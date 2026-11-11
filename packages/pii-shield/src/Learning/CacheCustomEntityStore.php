<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Learning;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Learned types in any PSR-6 pool (Redis, database, …), stored as one list without expiry.
 */
final class CacheCustomEntityStore implements CustomEntityStoreInterface
{
    public function __construct(
        private readonly CacheItemPoolInterface $pool,
        private readonly string $key = 'pii_shield.learned_types',
    ) {
    }

    public function all(): array
    {
        $item = $this->pool->getItem($this->key);
        $data = $item->isHit() ? $item->get() : [];

        $types = [];
        foreach (\is_array($data) ? $data : [] as $entry) {
            if (\is_array($entry)) {
                $types[] = CustomEntityType::fromArray($entry);
            }
        }
        usort($types, static fn (CustomEntityType $a, CustomEntityType $b): int => strcmp($a->name, $b->name));

        return $types;
    }

    public function get(string $name): ?CustomEntityType
    {
        foreach ($this->all() as $type) {
            if ($type->name === $name) {
                return $type;
            }
        }

        return null;
    }

    public function save(CustomEntityType $type): void
    {
        $this->write([...array_filter($this->all(), static fn (CustomEntityType $t): bool => $t->name !== $type->name), $type]);
    }

    public function remove(string $name): void
    {
        $this->write(array_filter($this->all(), static fn (CustomEntityType $t): bool => $t->name !== $name));
    }

    /**
     * @param array<CustomEntityType> $types
     */
    private function write(array $types): void
    {
        $item = $this->pool->getItem($this->key);
        $item->set(array_values(array_map(static fn (CustomEntityType $t): array => $t->toArray(), $types)));
        $this->pool->save($item);
    }
}
