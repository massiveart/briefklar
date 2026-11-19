<?php

declare(strict_types=1);

namespace Clarifile\PiiShield\Detector;

use Clarifile\PiiShield\Entity;
use Clarifile\PiiShield\EntityType;
use Clarifile\PiiShield\Validator\IbanValidator;
use Clarifile\PiiShield\Validator\ValidatorInterface;

final class IbanDetector implements DetectorInterface
{
    private const string PATTERN = '/\b[A-Z]{2}\d{2}(?:(?: |[ \t]*\n[ \t]*)?[A-Z0-9]{4}){2,7}(?:(?: |[ \t]*\n[ \t]*)?[A-Z0-9]{1,3})?\b/u';

    public function __construct(
        private readonly ValidatorInterface $validator = new IbanValidator(),
    ) {
    }

    public function detect(string $text): array
    {
        $entities = [];
        foreach (RegexMatcher::matchAll(self::PATTERN, $text) as $match) {
            $value = $this->longestValidPrefix($match['value']);
            if (null !== $value) {
                $entities[] = new Entity(EntityType::Iban, $value, $match['offset'], 1.0, 'iban');
            }
        }

        return $entities;
    }

    /**
     * The greedy pattern may swallow a following uppercase token ("... 3201 BIC"), also from the next line.
     * Drop trailing whitespace-separated groups until the checksum matches.
     */
    private function longestValidPrefix(string $candidate): ?string
    {
        while (true) {
            if ($this->validator->isValid($candidate)) {
                return $candidate;
            }
            if (1 !== preg_match('/^(.*\S)\s+\S+$/s', $candidate, $shorter)) {
                return null;
            }
            $candidate = $shorter[1];
        }
    }
}
