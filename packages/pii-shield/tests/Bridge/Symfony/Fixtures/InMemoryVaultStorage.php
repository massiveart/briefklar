<?php

declare(strict_types=1);

namespace Clarifile\PiiShield\Tests\Bridge\Symfony\Fixtures;

use Clarifile\PiiShield\Vault\Storage\VaultStorageInterface;

/**
 * Stands in for an application's own vault storage (database, S3, ...) in the bundle tests.
 */
final class InMemoryVaultStorage implements VaultStorageInterface
{
    /** @var array<string, string> */
    public array $payloads = [];

    public function put(string $id, string $payload, int $ttl): void
    {
        $this->payloads[$id] = $payload;
    }

    public function get(string $id): ?string
    {
        return $this->payloads[$id] ?? null;
    }

    public function remove(string $id): void
    {
        unset($this->payloads[$id]);
    }
}
