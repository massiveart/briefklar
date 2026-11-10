<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Vault\Storage;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Stores ciphertext in any PSR-6 pool, e.g. Symfony Cache with Redis, filesystem or PDO.
 *
 * Keys are hashed: PSR-6 forbids some characters, and ids may come from outside.
 */
final class Psr6CacheStorage implements VaultStorageInterface
{
    public function __construct(
        private readonly CacheItemPoolInterface $pool,
        private readonly string $keyPrefix = 'pii_shield_vault.',
    ) {
    }

    public function put(string $id, string $payload, int $ttl): void
    {
        $item = $this->pool->getItem($this->key($id));
        $item->set($payload);
        $item->expiresAfter($ttl);
        $this->pool->save($item);
    }

    public function get(string $id): ?string
    {
        $item = $this->pool->getItem($this->key($id));
        if (!$item->isHit()) {
            return null;
        }

        $value = $item->get();

        return \is_string($value) ? $value : null;
    }

    public function remove(string $id): void
    {
        $this->pool->deleteItem($this->key($id));
    }

    private function key(string $id): string
    {
        return $this->keyPrefix.hash('sha256', $id);
    }
}
