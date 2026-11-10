<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Vault\Storage;

/**
 * Raw byte storage behind EncryptedVault. Only ever receives ciphertext.
 * Implement this to plug in any backend (Redis, database, files, ...).
 */
interface VaultStorageInterface
{
    /**
     * @param positive-int $ttl seconds
     */
    public function put(string $id, string $payload, int $ttl): void;

    public function get(string $id): ?string;

    public function remove(string $id): void;
}
