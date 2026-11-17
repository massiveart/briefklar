<?php

declare(strict_types=1);

namespace App\Payment;

use App\Explanation\LetterExplanation;

/**
 * Reads the bank transfer a letter asks for from the letter text itself.
 *
 * A wrong IBAN or amount in a payment QR code would send money to the wrong place, so nothing here
 * is taken from the model answer unchecked. Every field must be unambiguous in the letter:
 * - IBAN: exactly one IBAN with a valid checksum, else no payment at all
 * - payee: the sender from the answer, only if it is written in the letter, else no payment
 * - amount: only if the letter states exactly one euro amount and the answer names the same one
 * - reference or text: only if the letter says "Verwendungszweck: …" with exactly one value
 * Whatever is not certain is left empty for the user to fill in.
 */
final class PaymentDetailsExtractor
{
    /** IBAN lengths per country (ISO 13616 registry) for the SEPA countries a letter here may name. */
    private const array IBAN_LENGTHS = [
        'AT' => 20, 'BE' => 16, 'CH' => 21, 'CZ' => 24, 'DE' => 22, 'ES' => 24, 'FR' => 27, 'HR' => 21,
        'HU' => 28, 'IT' => 27, 'LI' => 21, 'LU' => 20, 'NL' => 18, 'PL' => 28, 'SI' => 19, 'SK' => 24,
    ];

    private const string IBAN_START = '/\b([A-Z]{2})(\d{2})(?=[ A-Z0-9])/';
    private const string AMOUNT = '/(?:(?:€|EUR)\s*)?\b(\d{1,3}(?:\.\d{3})+|\d+),(\d{2})\b(?:\s*(?:Euro|EUR|€))?/u';
    private const string REMITTANCE = '/\b(?:Verwendungszweck|Zahlungsreferenz)\s*:\s*(.+?)(?=[.;,]\s|[.;]?$)/u';

    public function extract(string $letter, LetterExplanation $explanation): ?PaymentDetails
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $letter));

        $ibans = $this->ibans($text);
        $payee = trim($explanation->sender);
        if (1 !== \count($ibans) || '' === $payee || mb_strlen($payee) > 70 || !str_contains($text, $payee)) {
            return null;
        }

        $remittance = $this->remittance($text);
        $isReference = null !== $remittance && CreditorReference::isValid($remittance);

        return new PaymentDetails(
            $payee,
            $ibans[0],
            $this->amountCents($text, $explanation->amount),
            $isReference ? IbanChecksum::normalize($remittance) : null,
            $isReference || null === $remittance || mb_strlen($remittance) > 140 ? null : $remittance,
        );
    }

    /**
     * @return list<string> distinct valid IBANs, compact
     */
    private function ibans(string $text): array
    {
        preg_match_all(self::IBAN_START, $text, $starts, \PREG_OFFSET_CAPTURE | \PREG_SET_ORDER);

        $found = [];
        foreach ($starts as $start) {
            $length = self::IBAN_LENGTHS[$start[1][0]] ?? null;
            if (null === $length) {
                continue;
            }
            // Take exactly the country's length of characters, spaces in between allowed.
            $tail = substr($text, $start[0][1]);
            if (1 !== preg_match('/^(?:[A-Z0-9] ?){'.$length.'}/', $tail, $match)) {
                continue;
            }
            $iban = IbanChecksum::normalize($match[0]);
            // The next character must not continue the number, else the length was wrong.
            $next = substr($tail, \strlen(rtrim($match[0])), 1);
            if (IbanChecksum::isValid($iban) && 1 !== preg_match('/[A-Z0-9]/', $next)) {
                $found[$iban] = true;
            }
        }

        return array_keys($found);
    }

    private function amountCents(string $text, ?string $answered): ?int
    {
        if (null === $answered || 1 !== preg_match(self::AMOUNT, $answered, $named)) {
            return null;
        }

        preg_match_all(self::AMOUNT, $text, $matches, \PREG_SET_ORDER);
        $inLetter = [];
        foreach ($matches as $match) {
            if (str_contains($match[0], 'Euro') || str_contains($match[0], 'EUR') || str_contains($match[0], '€')) {
                $inLetter[self::cents($match)] = true;
            }
        }

        return [self::cents($named)] === array_keys($inLetter) ? self::cents($named) : null;
    }

    /**
     * @param array<int|string, string> $match
     */
    private static function cents(array $match): int
    {
        return (int) str_replace('.', '', $match[1]) * 100 + (int) $match[2];
    }

    private function remittance(string $text): ?string
    {
        preg_match_all(self::REMITTANCE, $text, $matches);
        $values = array_values(array_unique(array_map(static fn (string $v): string => trim($v, " \t.;,"), $matches[1])));

        return 1 === \count($values) && '' !== $values[0] ? $values[0] : null;
    }
}
