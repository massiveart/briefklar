<?php

declare(strict_types=1);

namespace Briefklar\PiiShield;

use Briefklar\PiiShield\Detector\AtSvnrDetector;
use Briefklar\PiiShield\Detector\BirthDateDetector;
use Briefklar\PiiShield\Detector\DetectorInterface;
use Briefklar\PiiShield\Detector\EmailDetector;
use Briefklar\PiiShield\Detector\FileNumberDetector;
use Briefklar\PiiShield\Detector\IbanDetector;
use Briefklar\PiiShield\Detector\LicensePlateDetector;
use Briefklar\PiiShield\Detector\PhoneDetector;
use Briefklar\PiiShield\Detector\PostalAddressDetector;
use Briefklar\PiiShield\Detector\SalutationPersonDetector;
use Briefklar\PiiShield\Detector\TaxNumberDetector;

/**
 * Runs all detectors, resolves overlaps and replaces each entity with a consistent placeholder.
 *
 * - Overlaps: the longer span wins; on equal length the higher confidence wins.
 * - Consistency: the same value always gets the same placeholder within one text.
 * - Propagation: every further occurrence of a detected value is redacted too, even where
 *   no detector would have found it on its own (e.g. a file number repeated without keyword).
 * - Person aliases: a surname that also occurs as last part of a full name maps to the
 *   full name's placeholder. Restore yields the full name (see README, "Restore").
 */
final class PiiShield implements PiiShieldInterface
{
    private const int MIN_PROPAGATION_LENGTH = 3;

    /**
     * Surnames are only derived from confidently detected full names; an uncertain
     * "Herr Muster Bescheid" must not turn every "Bescheid" into a person.
     */
    private const float SURNAME_PROPAGATION_MIN_CONFIDENCE = 0.8;

    /**
     * @param iterable<DetectorInterface> $detectors
     */
    public function __construct(
        private readonly iterable $detectors,
    ) {
    }

    /**
     * Offline, regex- and checksum-based detectors for all entity types.
     */
    public static function withDefaultDetectors(): self
    {
        return new self([
            new AtSvnrDetector(),
            new IbanDetector(),
            new TaxNumberDetector(),
            new EmailDetector(),
            new PhoneDetector(),
            new BirthDateDetector(),
            new FileNumberDetector(),
            new LicensePlateDetector(),
            new SalutationPersonDetector(),
            new PostalAddressDetector(),
        ]);
    }

    public function redact(string $text): RedactionResult
    {
        $detected = [];
        foreach ($this->detectors as $detector) {
            foreach ($detector->detect($text) as $entity) {
                $detected[] = $entity;
            }
        }

        $entities = $this->resolveOverlaps($detected);
        $entities = $this->resolveOverlaps([...$entities, ...$this->propagate($text, $entities)]);

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
        $seen = [];
        $unknown = [];

        $restored = Placeholder::replaceAll($text, static function (string $canonical, string $original) use ($redaction, &$seen, &$unknown): string {
            if (!\array_key_exists($canonical, $redaction->vault)) {
                $unknown[$canonical] = true;

                return $original;
            }
            $seen[$canonical] = true;

            return $redaction->vault[$canonical];
        });

        return new RestoreResult(
            $restored,
            array_keys($unknown),
            array_values(array_diff(array_keys($redaction->vault), array_keys($seen))),
        );
    }

    /**
     * Greedy: take spans by length (desc), then confidence (desc), skip anything overlapping.
     *
     * @param list<Entity> $entities
     *
     * @return list<Entity> non-overlapping, sorted by offset
     */
    private function resolveOverlaps(array $entities): array
    {
        usort($entities, static fn (Entity $a, Entity $b): int => [$b->length(), $b->confidence, $a->offset] <=> [$a->length(), $a->confidence, $b->offset]);

        $kept = [];
        foreach ($entities as $candidate) {
            foreach ($kept as $existing) {
                if ($candidate->offset < $existing->offset + $existing->length() && $existing->offset < $candidate->offset + $candidate->length()) {
                    continue 2;
                }
            }
            $kept[] = $candidate;
        }

        usort($kept, static fn (Entity $a, Entity $b): int => $a->offset <=> $b->offset);

        return $kept;
    }

    /**
     * Finds further whole-word occurrences of detected values, and of surnames of confidently detected full names.
     *
     * @param list<Entity> $entities
     *
     * @return list<Entity>
     */
    private function propagate(string $text, array $entities): array
    {
        $needles = [];
        foreach ($entities as $entity) {
            $needles[$entity->type->value."\0".$entity->value] = [$entity->type, $entity->value];
            if (EntityType::Person === $entity->type
                && $entity->confidence >= self::SURNAME_PROPAGATION_MIN_CONFIDENCE
                && null !== $surname = self::surname($entity->value)) {
                $needles[$entity->type->value."\0".$surname] = [$entity->type, $surname];
            }
        }

        $found = [];
        foreach ($needles as [$type, $value]) {
            if (mb_strlen($value) < self::MIN_PROPAGATION_LENGTH) {
                continue;
            }
            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($value, '/').'(?![\p{L}\p{N}])/u';
            foreach (Detector\RegexMatcher::matchAll($pattern, $text) as $match) {
                $found[] = new Entity($type, $match['value'], $match['offset'], 0.5, 'propagation');
            }
        }

        return $found;
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
        $fullNames = array_filter($entities, static fn (Entity $e): bool => EntityType::Person === $e->type && null !== self::surname($e->value));

        foreach ([...$fullNames, ...$entities] as $entity) {
            $type = $entity->type->value;
            if (isset($byValue[$type][$entity->value])) {
                continue;
            }

            if (EntityType::Person === $entity->type && null === self::surname($entity->value)) {
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
     * Last word of a multi-word name, or null for single-word values.
     */
    private static function surname(string $name): ?string
    {
        $parts = preg_split('/\s+/u', trim($name));

        return false !== $parts && \count($parts) > 1 ? $parts[\count($parts) - 1] : null;
    }

    /**
     * @param array<string, string> $personPlaceholders value => placeholder
     */
    private static function fullNameFor(string $surname, array $personPlaceholders): ?string
    {
        foreach ($personPlaceholders as $fullName => $placeholder) {
            if (self::surname($fullName) === $surname) {
                return $placeholder;
            }
        }

        return null;
    }
}
