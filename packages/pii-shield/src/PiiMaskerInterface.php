<?php

declare(strict_types=1);

namespace Briefklar\PiiShield;

/**
 * Irreversible masking for logs, debug output and previews (docs/adr/0005).
 * Use PiiShieldInterface::redact() for text that goes to a model provider.
 */
interface PiiMaskerInterface
{
    public function mask(string $text): string;
}
