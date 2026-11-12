<?php

declare(strict_types=1);

namespace App\Payment;

/**
 * ISO 7064 mod 97-10, shared by IBAN (ISO 13616) and RF creditor reference (ISO 11649):
 * move the first four characters to the end, letters become 10..35, the result must be 1.
 */
final class Mod97
{
    public static function isValid(string $upperAlphanumeric): bool
    {
        $remainder = 0;
        foreach (str_split(substr($upperAlphanumeric, 4).substr($upperAlphanumeric, 0, 4)) as $char) {
            $digits = ctype_digit($char) ? $char : (string) (\ord($char) - 55);
            foreach (str_split($digits) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }

        return 1 === $remainder;
    }
}
