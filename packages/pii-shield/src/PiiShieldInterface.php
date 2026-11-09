<?php

declare(strict_types=1);

namespace Briefklar\PiiShield;

/**
 * Shared contract between pii-shield and its consumers.
 * Changing this interface requires agreement of both workstream owners (see docs/PLAN.md).
 */
interface PiiShieldInterface
{
    /**
     * Replaces detected personal data with consistent, typed placeholders.
     */
    public function redact(string $text): RedactionResult;

    /**
     * Replaces placeholders in a (model-generated) text with the original values.
     */
    public function restore(string $text, RedactionResult $redaction): RestoreResult;
}
