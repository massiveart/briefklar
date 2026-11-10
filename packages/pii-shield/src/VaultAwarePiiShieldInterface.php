<?php

declare(strict_types=1);

namespace Briefklar\PiiShield;

use Briefklar\PiiShield\Exception\VaultException;

/**
 * Unmasking across requests: redact() stores the mapping in a vault and sets RedactionResult::$vaultId,
 * a later request restores by id (docs/adr/0005).
 */
interface VaultAwarePiiShieldInterface extends PiiShieldInterface
{
    /**
     * @throws VaultException  if the id is unknown or expired, or the entry cannot be read
     * @throws \LogicException if no vault is configured
     */
    public function restoreFromVault(string $text, string $vaultId): RestoreResult;

    /**
     * Deletes the mapping immediately (end of conversation, right to erasure).
     *
     * @throws \LogicException if no vault is configured
     */
    public function forget(string $vaultId): void;
}
