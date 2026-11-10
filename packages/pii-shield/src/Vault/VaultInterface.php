<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Vault;

use Briefklar\PiiShield\Exception\VaultException;

/**
 * Stores placeholder => original mappings between requests (see docs/adr/0005).
 *
 * Implementations that persist data outside the process must encrypt it; use
 * EncryptedVault with a VaultStorageInterface instead of implementing this directly.
 */
interface VaultInterface
{
    /**
     * @param array<string, string> $mapping placeholder => original value
     * @param int                   $ttl     seconds, must be positive; mappings must not live forever
     *
     * @throws \InvalidArgumentException if the TTL is not positive
     */
    public function store(string $id, array $mapping, int $ttl): void;

    /**
     * @return array<string, string>|null null if unknown or expired
     *
     * @throws VaultException if stored data cannot be read (tampered, wrong key)
     */
    public function fetch(string $id): ?array;

    public function delete(string $id): void;
}
