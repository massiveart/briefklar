<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Detector;

use Briefklar\PiiShield\Entity;
use Briefklar\PiiShield\EntityType;
use Briefklar\PiiShield\Validator\DeTaxIdValidator;
use Briefklar\PiiShield\Validator\ValidatorInterface;

/**
 * AT Steuernummer (e.g. "46 123/4567"), DE Steuernummer (e.g. "181/815/08155")
 * and DE Steuer-ID (11 digits, checksum-validated).
 */
final class TaxNumberDetector implements DetectorInterface
{
    private const string AT_STEUERNUMMER = '/(?<![\d\/])\d{2}[ -]?\d{3}\/\d{4}(?![\d\/])/u';
    private const string DE_STEUERNUMMER = '/(?<![\d\/])\d{2,3}\/\d{3,4}\/\d{4,5}(?![\d\/])/u';
    private const string DE_STEUER_ID = '/(?<!\d)\d{2} ?\d{3} ?\d{3} ?\d{3}(?!\d)/u';

    public function __construct(
        private readonly ValidatorInterface $taxIdValidator = new DeTaxIdValidator(),
    ) {
    }

    public function detect(string $text): array
    {
        $entities = [];
        foreach ([self::AT_STEUERNUMMER, self::DE_STEUERNUMMER] as $pattern) {
            foreach (RegexMatcher::matchAll($pattern, $text) as $match) {
                $entities[] = new Entity(EntityType::TaxNumber, $match['value'], $match['offset'], 0.9, 'tax_number');
            }
        }
        foreach (RegexMatcher::matchAll(self::DE_STEUER_ID, $text) as $match) {
            if ($this->taxIdValidator->isValid($match['value'])) {
                $entities[] = new Entity(EntityType::TaxNumber, $match['value'], $match['offset'], 1.0, 'de_tax_id');
            }
        }

        usort($entities, static fn (Entity $a, Entity $b): int => $a->offset <=> $b->offset);

        return $entities;
    }
}
