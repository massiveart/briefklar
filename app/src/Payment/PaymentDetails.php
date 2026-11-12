<?php

declare(strict_types=1);

namespace App\Payment;

/**
 * A bank transfer as the letter asks for it, taken from the letter text (never from the model answer).
 * Contains no data of the recipient of the letter, only the payee's.
 */
final readonly class PaymentDetails
{
    /**
     * @param string      $iban        compact, upper case, checksum valid
     * @param int|null    $amountCents null if the letter does not state one amount unambiguously
     * @param string|null $reference   ISO 11649 creditor reference ("RF…"), exclusive with $text
     * @param string|null $text        unstructured remittance information, exclusive with $reference
     */
    public function __construct(
        public string $payee,
        public string $iban,
        public ?int $amountCents = null,
        public ?string $reference = null,
        public ?string $text = null,
    ) {
        if (null !== $reference && null !== $text) {
            throw new \InvalidArgumentException('A transfer has either a structured reference or a remittance text, not both.');
        }
    }

    /**
     * IBAN in groups of four, as printed on letters.
     */
    public function formattedIban(): string
    {
        return implode(' ', str_split($this->iban, 4));
    }
}
