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
     * Last word of a multi-word name, or null for single-word values.
     */
    public static function surname(string $name): ?string
    {
        $parts = preg_split('/\s+/u', trim($name));

        return false !== $parts && \count($parts) > 1 ? $parts[\count($parts) - 1] : null;
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
     * Greedy: take spans by length (desc), then confidence (desc), skip anything overlapping.
     * Covered offsets are kept in a map, so each candidate costs its own length, not the number of kept spans.
     *
     * @param list<Entity> $entities
     *
     * @return list<Entity>
     */
    private static function resolveOverlaps(array $entities): array
    {
        usort($entities, static fn (Entity $a, Entity $b): int => [$b->length(), $b->confidence, $a->offset] <=> [$a->length(), $a->confidence, $b->offset]);

        $kept = [];
        $covered = [];
        foreach ($entities as $candidate) {
            $end = $candidate->offset + $candidate->length();
            for ($i = $candidate->offset; $i < $end; ++$i) {
                if (isset($covered[$i])) {
                    continue 2;
                }
            }
            for ($i = $candidate->offset; $i < $end; ++$i) {
                $covered[$i] = true;
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
            $needles[$entity->typeName()."\0".$entity->value] = [$entity->type, $entity->value, $entity->label];
            if (EntityType::Person === $entity->type
                && $entity->confidence >= self::SURNAME_PROPAGATION_MIN_CONFIDENCE
                && null !== $surname = self::surname($entity->value)) {
                $needles[$entity->typeName()."\0".$surname] = [$entity->type, $surname, $entity->label];
            }
        }

        // One pass over the text instead of one regex per value. Values are indexed by their first word,
        // then by the byte position of that word in the value and by byte length; at each word of the text,
        // the substring of that length is looked up by hash. Cost per word: number of distinct lengths.
        $index = [];
        foreach ($needles as [$type, $value, $label]) {
            if (mb_strlen($value) < self::MIN_PROPAGATION_LENGTH || 1 !== preg_match('/[\p{L}\p{N}]+/u', $value, $first, \PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $index[$first[0][0]][$first[0][1]][\strlen($value)][$value][] = ['type' => $type, 'value' => $value, 'label' => $label];
        }
        if ([] === $index || false === preg_match_all('/[\p{L}\p{N}]+/u', $text, $words, \PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $hits = [];
        foreach ($words[0] as [$word, $wordByte]) {
            foreach ($index[$word] ?? [] as $prefix => $byLength) {
                $start = $wordByte - $prefix;
                if ($start < 0) {
                    continue;
                }
                foreach ($byLength as $length => $byValue) {
                    $candidates = $byValue[substr($text, $start, $length)] ?? null;
                    if (null !== $candidates
                        && 1 === preg_match('/\G(?<![\p{L}\p{N}])/u', $text, $m, 0, $start)
                        && 1 === preg_match('/\G(?![\p{L}\p{N}])/u', $text, $m, 0, $start + $length)) {
                        foreach ($candidates as $needle) {
                            $hits[] = [$start, $needle];
                        }
                    }
                }
            }
        }
        usort($hits, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $found = [];
        $byte = 0;
        $codePoint = 0;
        foreach ($hits as [$start, $needle]) {
            $codePoint += mb_strlen(substr($text, $byte, $start - $byte));
            $byte = $start;
            $found[] = new Entity($needle['type'], $needle['value'], $codePoint, 0.5, 'propagation', $needle['label']);
        }

        return $found;
    }
}
