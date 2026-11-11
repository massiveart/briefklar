<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Briefklar\PiiShield\Detector\DetectorInterface;
use Briefklar\PiiShield\Exception\DetectionFailedException;

/**
 * Test-only detector (config/services_test.yaml): fails like an unreachable detection model
 * whenever the text contains MARKER, so tests can check that nothing is sent unredacted.
 */
final class FailingDetector implements DetectorInterface
{
    public const string MARKER = 'PII-DETECTOR-AUSFALL';

    public function detect(string $text): array
    {
        if (str_contains($text, self::MARKER)) {
            throw new DetectionFailedException('Scripted detector failure.');
        }

        return [];
    }
}
