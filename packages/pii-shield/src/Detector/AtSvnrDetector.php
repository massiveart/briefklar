<?php

declare(strict_types=1);

namespace Clarifile\PiiShield\Detector;

use Clarifile\PiiShield\Entity;
use Clarifile\PiiShield\EntityType;
use Clarifile\PiiShield\Validator\AtSvnrValidator;
use Clarifile\PiiShield\Validator\ValidatorInterface;

/**
 * Austrian social security number: "4568 150392", "4568-150392", "4568150392", also split across a line break (OCR).
 * With a hyphen or line break the last six digits must be a valid date (DDMMYY).
 */
final class AtSvnrDetector implements DetectorInterface
{
    private const string PATTERN = '/(?<!\d)\d{4}(?:[ -]|[ \t]*\n[ \t]*)?\d{6}(?!\d)/u';

    public function __construct(
        private readonly ValidatorInterface $validator = new AtSvnrValidator(),
    ) {
    }

    public function detect(string $text): array
    {
        $entities = [];
        foreach (RegexMatcher::matchAll(self::PATTERN, $text) as $match) {
            if ($this->validator->isValid($match['value']) && (ctype_digit(str_replace(' ', '', $match['value'])) || self::hasBirthDate($match['value']))) {
                $entities[] = new Entity(EntityType::AtSvnr, $match['value'], $match['offset'], 1.0, 'at_svnr');
            }
        }

        return $entities;
    }

    /**
     * A hyphen or line break also joins file numbers ("VWA-2026-100084") and a date's year with the next line,
     * and the checksum passes for about one in eleven: these variants need a plausible DDMMYY birth date.
     */
    private static function hasBirthDate(string $value): bool
    {
        $birth = substr((string) preg_replace('/\D/', '', $value), 4);

        return checkdate((int) substr($birth, 2, 2), (int) substr($birth, 0, 2), 2000 + (int) substr($birth, 4, 2));
    }
}
