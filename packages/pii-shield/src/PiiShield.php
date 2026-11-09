<?php

declare(strict_types=1);

namespace Briefklar\PiiShield;

use Briefklar\PiiShield\Detector\DetectorInterface;

/**
 * Replaces each detected entity with a consistent, typed placeholder (see EntityExtractor for detection rules).
 *
 * - Consistency: the same value always gets the same placeholder within one text.
 * - Person aliases: a surname that also occurs as last part of a full name maps to the
 *   full name's placeholder. Restore yields the full name (see README, "Restore").
 */
final class PiiShield implements PiiShieldInterface
{
    private readonly EntityExtractor $extractor;

    /**
     * @param iterable<DetectorInterface> $detectors
     */
    public function __construct(iterable $detectors)
    {
        $this->extractor = new EntityExtractor($detectors);
    }

    /**
     * Offline, regex- and checksum-based detectors for all entity types.
     */
    public static function withDefaultDetectors(): self
    {
        return new self(EntityExtractor::defaultDetectors());
    }

    public function redact(string $text): RedactionResult
    {
        $entities = $this->extractor->extract($text);

        if ([] === $entities) {
            return new RedactionResult($text, [], []);
        }

        $placeholders = $this->assignPlaceholders($entities);

        $redacted = '';
        $cursor = 0;
        foreach ($entities as $entity) {
            $redacted .= mb_substr($text, $cursor, $entity->offset - $cursor).$placeholders['byValue'][$entity->type->value][$entity->value];
            $cursor = $entity->offset + $entity->length();
        }
        $redacted .= mb_substr($text, $cursor);

        return new RedactionResult($redacted, $placeholders['vault'], $entities);
    }

    public function restore(string $text, RedactionResult $redaction): RestoreResult
    {
        return self::restoreWith($text, $redaction->vault);
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
            $type = $entity->type->value;
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
            $placeholder = Placeholder::format($entity->type, $counters[$type]);
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
