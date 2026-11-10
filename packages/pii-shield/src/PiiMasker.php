<?php

declare(strict_types=1);

namespace Briefklar\PiiShield;

use Briefklar\PiiShield\Detector\DetectorInterface;
use Briefklar\PiiShield\Masking\MaskingStrategyInterface;
use Briefklar\PiiShield\Masking\PartialMask;
use Briefklar\PiiShield\Masking\TypeLabelMask;

/**
 * Same detection as PiiShield, but replaces entities irreversibly. No vault, no way back.
 *
 * Defaults: IBAN keeps first and last 4 characters, phone keeps the last 2, everything else becomes a type label.
 */
final class PiiMasker implements PiiMaskerInterface
{
    private readonly EntityExtractor $extractor;

    /** @var array<string, MaskingStrategyInterface> */
    private readonly array $strategies;

    /**
     * @param iterable<DetectorInterface>             $detectors
     * @param array<string, MaskingStrategyInterface> $strategies keyed by EntityType value; merged over the defaults
     * @param list<string>                            $allowlist  values that are never masked (exact match)
     */
    public function __construct(
        iterable $detectors,
        array $strategies = [],
        private readonly MaskingStrategyInterface $fallback = new TypeLabelMask(),
        array $allowlist = [],
    ) {
        $this->extractor = new EntityExtractor($detectors, $allowlist);
        $this->strategies = [...self::defaultStrategies(), ...$strategies];
    }

    public static function withDefaultDetectors(): self
    {
        return new self(EntityExtractor::defaultDetectors());
    }

    public function mask(string $text): string
    {
        $masked = '';
        $cursor = 0;
        foreach ($this->extractor->extract($text) as $entity) {
            $strategy = $this->strategies[$entity->typeName()] ?? $this->strategies[$entity->type->value] ?? $this->fallback;
            $masked .= mb_substr($text, $cursor, $entity->offset - $cursor).$strategy->mask($entity);
            $cursor = $entity->offset + $entity->length();
        }

        return $masked.mb_substr($text, $cursor);
    }

    /**
     * @return array<string, MaskingStrategyInterface>
     */
    public static function defaultStrategies(): array
    {
        return [
            EntityType::Iban->value => new PartialMask(4, 4),
            EntityType::Phone->value => new PartialMask(0, 2),
        ];
    }
}
