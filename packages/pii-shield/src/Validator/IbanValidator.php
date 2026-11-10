<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Validator;

/**
 * IBAN according to ISO 13616 (mod 97 check). Spaces are ignored, case-insensitive.
 */
final class IbanValidator implements ValidatorInterface
{
    public function isValid(string $candidate): bool
    {
        $iban = strtoupper(str_replace(' ', '', $candidate));

        if (1 !== preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) {
            return false;
        }

        $rearranged = substr($iban, 4).substr($iban, 0, 4);

        $remainder = 0;
        foreach (str_split($rearranged) as $char) {
            $value = ctype_digit($char) ? $char : (string) (\ord($char) - 55);
            foreach (str_split($value) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }

        return 1 === $remainder;
    }
}
