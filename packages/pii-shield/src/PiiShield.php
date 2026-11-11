<?php

declare(strict_types=1);

namespace Briefklar\PiiShield;

use Briefklar\PiiShield\Detector\DetectorInterface;
use Briefklar\PiiShield\Exception\VaultException;
use Briefklar\PiiShield\Vault\VaultInterface;

/**
 * Replaces each detected entity with a consistent, typed placeholder (see EntityExtractor for detection rules).
 *
 * - Consistency: the same value always gets the same placeholder within one text.
 * - Person aliases: a surname that also occurs as last part of a full name maps to the
 *   full name's placeholder. Restore yields the full name (see README, "Restore").
 */
final class PiiShield implements VaultAwarePiiShieldInterface
{
    public const int DEFAULT_VAULT_TTL = 3600;

    private readonly EntityExtractor $extractor;

    /**
     * @param iterable<DetectorInterface> $detectors
     * @param VaultInterface|null         $vault     optional; enables restoreFromVault() across requests
     * @param int                         $vaultTtl  seconds a stored mapping lives
     * @param list<string>                $allowlist values that are never redacted (exact match)
     */
    public function __construct(
        iterable $detectors,
        private readonly ?VaultInterface $vault = null,
        private readonly int $vaultTtl = self::DEFAULT_VAULT_TTL,
        array $allowlist = [],
    ) {
        if ($vaultTtl <= 0) {
            throw new \InvalidArgumentException('The vault TTL must be positive.');
        }
        $this->extractor = new EntityExtractor($detectors, $allowlist);
    }

    /**
     * Offline, regex- and checksum-based detectors for all entity types.
     */
    public static function withDefaultDetectors(?VaultInterface $vault = null, int $vaultTtl = self::DEFAULT_VAULT_TTL): self
    {
        return new self(EntityExtractor::defaultDetectors(), $vault, $vaultTtl);
    }

    public function redact(string $text): RedactionResult
    {
        $entities = $this->extractor->extract($text);

        $mapping = [];
        $redacted = $text;
        if ([] !== $entities) {
            $placeholders = $this->assignPlaceholders($entities);
            $mapping = $placeholders['vault'];

            $redacted = TextSpans::replace($text, $entities, static fn (Entity $entity): string => $placeholders['byValue'][$entity->typeName()][$entity->value]);
        }

        $vaultId = null;
        if (null !== $this->vault) {
            $vaultId = bin2hex(random_bytes(16));
            $this->vault->store($vaultId, $mapping, $this->vaultTtl);
        }

        return new RedactionResult($redacted, $mapping, $entities, $vaultId);
    }

    public function restore(string $text, RedactionResult $redaction): RestoreResult
    {
        return self::restoreWith($text, $redaction->vault);
    }

    public function restoreFromVault(string $text, string $vaultId): RestoreResult
    {
        $mapping = $this->requireVault()->fetch($vaultId)
            ?? throw new VaultException('Unknown or expired vault id.');

        return self::restoreWith($text, $mapping);
    }

    public function forget(string $vaultId): void
    {
        $this->requireVault()->delete($vaultId);
    }

    private function requireVault(): VaultInterface
    {
        return $this->vault ?? throw new \LogicException('No vault configured. Pass a VaultInterface to PiiShield to restore across requests.');
    }

    /**
     * @param array<string, string> $vault
     */
    private static function restoreWith(string $text, array $vault): RestoreResult
    {
        $seen = [];
        $unknown = [];

        $restored = Placeholder::replaceAll($text, static function (string $canonical, string $original) use ($vault, &$seen, &$unknown): string {
            if (!\array_key_exists($canonical, $vault)) {
                $unknown[$canonical] = true;

                return $original;
            }
            $seen[$canonical] = true;

            return $vault[$canonical];
        });

        return new RestoreResult(
            $restored,
            array_keys($unknown),
            array_values(array_diff(array_keys($vault), array_keys($seen))),
        );
    }

    /**
     * @param non-empty-list<Entity> $entities sorted by offset
     *
     * @return array{vault: array<string, string>, byValue: array<string, array<string, string>>}
     */
    private function assignPlaceholders(array $entities): array
    {
        $vault = [];
        $byValue = [];
        $counters = [];

        // Full names first, so surnames can be linked to them regardless of order in the text.
        $fullNames = array_filter($entities, static fn (Entity $e): bool => EntityType::Person === $e->type && null !== EntityExtractor::surname($e->value));

        foreach ([...$fullNames, ...$entities] as $entity) {
            $type = $entity->typeName();
            if (isset($byValue[$type][$entity->value])) {
                continue;
            }

            if (EntityType::Person === $entity->type && null === EntityExtractor::surname($entity->value)) {
                $alias = self::fullNameFor($entity->value, $byValue[$type] ?? []);
                if (null !== $alias) {
                    $byValue[$type][$entity->value] = $alias;
                    continue;
                }
            }

            $counters[$type] = ($counters[$type] ?? 0) + 1;
            $placeholder = Placeholder::format($entity->type, $counters[$type], $entity->label);
            $byValue[$type][$entity->value] = $placeholder;
            $vault[$placeholder] = $entity->value;
        }

        return ['vault' => $vault, 'byValue' => $byValue];
    }

    /**
     * @param array<string, string> $personPlaceholders value => placeholder
     */
    private static function fullNameFor(string $surname, array $personPlaceholders): ?string
    {
        foreach ($personPlaceholders as $fullName => $placeholder) {
            if (EntityExtractor::surname($fullName) === $surname) {
                return $placeholder;
            }
        }

        return null;
    }
}
