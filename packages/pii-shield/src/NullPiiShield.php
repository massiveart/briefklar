<?php

declare(strict_types=1);

namespace Briefklar\PiiShield;

/**
 * Pass-through implementation so the app can be built before the real shield exists.
 * Never use in the demo.
 */
final class NullPiiShield implements PiiShieldInterface
{
    public function redact(string $text): RedactionResult
    {
        return new RedactionResult($text, [], []);
    }

    public function restore(string $text, RedactionResult $redaction): RestoreResult
    {
        return new RestoreResult($text);
    }
}
