<?php

declare(strict_types=1);

namespace App\Payment;

/**
 * Structured creditor reference according to ISO 11649 ("RF" + 2 check digits + up to 21 characters).
 */
final class CreditorReference
{
    public static function isValid(string $candidate): bool
    {
        $reference = IbanChecksum::normalize($candidate);

        return 1 === preg_match('/^RF\d{2}[A-Z0-9]{1,21}$/', $reference) && Mod97::isValid($reference);
    }
}
