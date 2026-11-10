<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Vault;

use Psr\Clock\ClockInterface;

/**
 * Process-local vault. Suitable for one request or one long-running worker; not shared between processes.
 * Expired mappings are purged on every write, so long-running workers do not accumulate data.
 */
final class InMemoryVault implements VaultInterface
{
    /** @var array<string, array{mapping: array<string, string>, expiresAt: int}> */
    private array $entries = [];

    public function __construct(
        private readonly ?ClockInterface $clock = null,
    ) {
    }

    public function store(string $id, array $mapping, int $ttl): void
    {
        if ($ttl <= 0) {
            throw new \InvalidArgumentException('The TTL must be positive.');
        }

        $now = $this->now();
        $this->entries = array_filter($this->entries, static fn (array $e): bool => $e['expiresAt'] > $now);
        $this->entries[$id] = ['mapping' => $mapping, 'expiresAt' => $now + $ttl];
    }

    public function fetch(string $id): ?array
    {
        $entry = $this->entries[$id] ?? null;
        if (null === $entry) {
            return null;
        }
        if ($entry['expiresAt'] <= $this->now()) {
            unset($this->entries[$id]);

            return null;
        }

        return $entry['mapping'];
    }

    public function delete(string $id): void
    {
        unset($this->entries[$id]);
    }

    private function now(): int
    {
        return null !== $this->clock ? $this->clock->now()->getTimestamp() : time();
    }
}
