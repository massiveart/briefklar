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
use Briefklar\PiiShield\Detector\RegexMatcher;
use Briefklar\PiiShield\Detector\SalutationPersonDetector;
use Briefklar\PiiShield\Detector\TaxNumberDetector;

/**
 * Runs all detectors and returns a clean, non-overlapping list of entities.
 *
 * - Overlaps: the longer span wins; on equal length the higher confidence wins.
 * - Propagation: every further whole-word occurrence of a detected value is an entity too,
 *   even where no detector would have found it on its own (e.g. a file number repeated without keyword).
 * - Surnames of confidently detected full names are propagated as well.
 */
final class EntityExtractor
{
    private const int MIN_PROPAGATION_LENGTH = 3;

    /**
     * Surnames are only derived from confidently detected full names; an uncertain
     * "Herr Muster Bescheid" must not turn every "Bescheid" into a person.
     */
    private const float SURNAME_PROPAGATION_MIN_CONFIDENCE = 0.8;

    /** @var array<string, true> */
    private readonly array $allowlist;

    /**
     * @param iterable<DetectorInterface> $detectors
     * @param list<string>                $allowlist values that are never treated as PII (exact match)
     */
    public function __construct(
        private readonly iterable $detectors,
        array $allowlist = [],
    ) {
        $this->allowlist = array_fill_keys($allowlist, true);
    }

    /**
     * Offline, regex- and checksum-based detectors for all entity types.
     */
    public static function withDefaultDetectors(): self
    {
        return new self(self::defaultDetectors());
    }

    /**
     * @return list<DetectorInterface>
     */
    public static function defaultDetectors(): array
    {
        return [
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
        ];
    }

    /**
     * @return list<Entity> non-overlapping, sorted by offset
     */
    public function extract(string $text): array
    {
        $detected = [];
        foreach ($this->detectors as $detector) {
            foreach ($detector->detect($text) as $entity) {
                $detected[] = $entity;
            }
        }

        // Filter before propagation (an allowed value must not seed surnames) and after it.
        $entities = self::resolveOverlaps($this->withoutAllowed($detected));

        return self::resolveOverlaps($this->withoutAllowed([...$entities, ...self::propagate($text, $entities)]));
    }

    /**
     * @param list<Entity> $entities
     *
     * @return list<Entity>
     */
    private function withoutAllowed(array $entities): array
    {
        if ([] === $this->allowlist) {
            return $entities;
        }

        return array_values(array_filter($entities, fn (Entity $e): bool => !isset($this->allowlist[$e->value])));
    }

    /**
     * Last word of a multi-word name, or null for single-word values.
     */
    public static function surname(string $name): ?string
    {
        $parts = preg_split('/\s+/u', trim($name));

        return false !== $parts && \count($parts) > 1 ? $parts[\count($parts) - 1] : null;
    }

    /**
     * Greedy: take spans by length (desc), then confidence (desc), skip anything overlapping.
     *
     * @param list<Entity> $entities
     *
     * @return list<Entity>
     */
    private static function resolveOverlaps(array $entities): array
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
     * @param list<Entity> $entities
     *
     * @return list<Entity>
     */
    private static function propagate(string $text, array $entities): array
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
            foreach (RegexMatcher::matchAll($pattern, $text) as $match) {
                $found[] = new Entity($type, $match['value'], $match['offset'], 0.5, 'propagation');
            }
        }

        return $found;
    }
}
