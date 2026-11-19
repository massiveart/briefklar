<?php

declare(strict_types=1);

namespace Clarifile\PiiShield\Learning;

use Symfony\Component\Lock\LockFactory;

/**
 * Serialises writes to any CustomEntityStoreInterface with a lock from symfony/lock.
 *
 * save() and remove() of the built-in stores read the whole list, change it and write it back. Two processes
 * learning at the same moment can therefore overwrite each other. This decorator holds one lock around the
 * inner write, so the read-modify-write runs as a whole. Reads do not lock.
 *
 * Use a lock store every writing process shares: FlockStore on one host, a Redis or database store across hosts.
 */
final readonly class LockingCustomEntityStore implements CustomEntityStoreInterface
{
    public const string DEFAULT_RESOURCE = 'pii_shield.learned_types';

    public function __construct(
        private CustomEntityStoreInterface $inner,
        private LockFactory $locks,
        private string $resource = self::DEFAULT_RESOURCE,
    ) {
    }

    public function all(): array
    {
        return $this->inner->all();
    }

    public function get(string $name): ?CustomEntityType
    {
        return $this->inner->get($name);
    }

    public function save(CustomEntityType $type): void
    {
        $this->locked(fn () => $this->inner->save($type));
    }

    public function remove(string $name): void
    {
        $this->locked(fn () => $this->inner->remove($name));
    }

    /**
     * @param \Closure(): void $write
     */
    private function locked(\Closure $write): void
    {
        $lock = $this->locks->createLock($this->resource);
        $lock->acquire(true);
        try {
            $write();
        } finally {
            $lock->release();
        }
    }
}
