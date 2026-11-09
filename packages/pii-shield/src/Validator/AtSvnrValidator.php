<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Validator;

/**
 * Austrian social security number (Sozialversicherungsnummer).
 *
 * Format: 3-digit serial, 1 check digit, 6-digit birth date (DDMMYY).
 * Check digit = sum(digit_i * weight_i) mod 11 with weights 3,7,9,(check),5,8,4,2,1,6.
 * A remainder of 10 is never issued.
 */
final class AtSvnrValidator implements ValidatorInterface
{
    private const array WEIGHTS = [3, 7, 9, 0, 5, 8, 4, 2, 1, 6];

    public function isValid(string $candidate): bool
    {
        $digits = str_replace(' ', '', $candidate);

        if (1 !== preg_match('/^[1-9]\d{9}$/', $digits)) {
            return false;
        }

        $sum = 0;
        foreach (self::WEIGHTS as $i => $weight) {
            $sum += (int) $digits[$i] * $weight;
        }

        $check = $sum % 11;

        return 10 !== $check && $check === (int) $digits[3];
    }
}
