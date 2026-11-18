<?php

declare(strict_types=1);

namespace Clarifile\PiiShield\Detector;

use Clarifile\PiiShield\Entity;
use Clarifile\PiiShield\EntityType;
use Clarifile\PiiShield\Validator\AtSvnrValidator;
use Clarifile\PiiShield\Validator\ValidatorInterface;

final class AtSvnrDetector implements DetectorInterface
{
    private const string PATTERN = '/(?<!\d)\d{4} ?\d{6}(?!\d)/u';

    public function __construct(
        private readonly ValidatorInterface $validator = new AtSvnrValidator(),
    ) {
    }

    public function detect(string $text): array
    {
        $entities = [];
        foreach (RegexMatcher::matchAll(self::PATTERN, $text) as $match) {
            if ($this->validator->isValid($match['value'])) {
                $entities[] = new Entity(EntityType::AtSvnr, $match['value'], $match['offset'], 1.0, 'at_svnr');
            }
        }

        return $entities;
    }
}
