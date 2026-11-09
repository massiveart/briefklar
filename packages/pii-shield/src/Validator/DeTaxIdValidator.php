<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Validator;

/**
 * German tax identification number (Steuerliche Identifikationsnummer, 11 digits).
 *
 * Structure: no leading zero; within the first 10 digits exactly one digit occurs
 * two or three times, all others at most once. Check digit: ISO 7064 MOD 11,10.
 */
final class DeTaxIdValidator implements ValidatorInterface
{
    public function isValid(string $candidate): bool
    {
        $digits = str_replace(' ', '', $candidate);

        if (1 !== preg_match('/^[1-9]\d{10}$/', $digits)) {
            return false;
        }

        $counts = array_count_values(str_split(substr($digits, 0, 10)));
        $repeated = array_filter($counts, static fn (int $count): bool => $count > 1);
        if (1 !== \count($repeated) || max($repeated) > 3) {
            return false;
        }

        $product = 10;
        for ($i = 0; $i < 10; ++$i) {
            $sum = ((int) $digits[$i] + $product) % 10;
            if (0 === $sum) {
                $sum = 10;
            }
            $product = ($sum * 2) % 11;
        }

        $check = 11 - $product;
        if (10 === $check) {
            $check = 0;
        }

        return $check === (int) $digits[10];
    }
}
