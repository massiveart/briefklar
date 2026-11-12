<?php

declare(strict_types=1);

namespace App\Payment;

/**
 * IBAN check according to ISO 13616 (mod 97). Spaces are ignored, case-insensitive.
 *
 * pii-shield has a validator of its own; it finds IBANs to hide them, this one checks an IBAN
 * someone is about to pay to. Same algorithm, different purpose, so the app keeps its own.
 */
final class IbanChecksum
{
    public static function normalize(string $candidate): string
    {
        return strtoupper((string) preg_replace('/\s+/u', '', $candidate));
    }

    public static function isValid(string $candidate): bool
    {
        $iban = self::normalize($candidate);
        if (1 !== preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) {
            return false;
        }

        return Mod97::isValid($iban);
    }
}
