<?php

declare(strict_types=1);

namespace App\Payment;

/**
 * Text content of an EPC QR code for a SEPA credit transfer ("GiroCode", "Zahlen mit Code"),
 * according to EPC069-12 v3.0, section 2.2: version 002, UTF-8, no BIC (optional within the EEA),
 * elements separated by LF, nothing after the last populated element, at most 331 bytes.
 */
final class EpcPayload
{
    public const int MAX_BYTES = 331;

    private const int MAX_NAME = 70;
    private const int MAX_IBAN = 34;
    private const int MAX_REFERENCE = 35;
    private const int MAX_TEXT = 140;
    private const int MAX_AMOUNT_CENTS = 99_999_999_999;

    /**
     * @throws \InvalidArgumentException if a field does not fit the specification
     */
    public static function of(PaymentDetails $payment): string
    {
        self::assertLength('name', $payment->payee, self::MAX_NAME);
        self::assertLength('IBAN', $payment->iban, self::MAX_IBAN);
        if (null !== $payment->reference) {
            self::assertLength('reference', $payment->reference, self::MAX_REFERENCE);
        }
        if (null !== $payment->text) {
            self::assertLength('remittance text', $payment->text, self::MAX_TEXT);
        }

        $elements = [
            'BCD',
            '002',
            '1',
            'SCT',
            '',
            $payment->payee,
            $payment->iban,
            null === $payment->amountCents ? '' : self::amount($payment->amountCents),
            '',
            $payment->reference ?? '',
            $payment->text ?? '',
        ];
        while ('' === end($elements)) {
            array_pop($elements);
        }

        $payload = implode("\n", $elements);
        if (\strlen($payload) > self::MAX_BYTES) {
            throw new \InvalidArgumentException(\sprintf('EPC payload has %d bytes, at most %d are allowed.', \strlen($payload), self::MAX_BYTES));
        }

        return $payload;
    }

    private static function amount(int $cents): string
    {
        if ($cents < 1 || $cents > self::MAX_AMOUNT_CENTS) {
            throw new \InvalidArgumentException('EPC amount must be between 0.01 and 999999999.99 euros.');
        }

        return \sprintf('EUR%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    private static function assertLength(string $field, string $value, int $max): void
    {
        $length = mb_strlen($value);
        if (0 === $length || $length > $max || str_contains($value, "\n")) {
            throw new \InvalidArgumentException(\sprintf('EPC %s must be 1 to %d characters on one line.', $field, $max));
        }
    }
}
