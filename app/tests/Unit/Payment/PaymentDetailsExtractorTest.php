<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment;

use App\Explanation\LetterExplanation;
use App\Payment\PaymentDetails;
use App\Payment\PaymentDetailsExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Synthetic letter excerpts; IBANs are documentation examples.
 */
final class PaymentDetailsExtractorTest extends TestCase
{
    private const string SENDER = 'Bezirkshauptmannschaft Musterstadt';
    private const string LETTER = <<<'TXT'
        Bezirkshauptmannschaft Musterstadt
        Zahl: BHMS-VK-2026/48213
        Es wird eine Geldstrafe von 110,00 Euro verhängt.
        Zahlungshinweis: Bitte überweisen Sie den Betrag binnen zwei Wochen ab Zustellung auf das Konto
        IBAN AT61 1904 3002 3457 3201, Verwendungszweck: BHMS-VK-2026/48213.
        Rechtsmittelbelehrung: Sie haben das Recht, Einspruch zu erheben.
        TXT;

    public function testReadsTheTransferFromTheLetter(): void
    {
        $payment = $this->extract(self::LETTER);

        self::assertEquals(new PaymentDetails(self::SENDER, 'AT611904300234573201', 11000, text: 'BHMS-VK-2026/48213'), $payment);
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function noPayment(): iterable
    {
        yield 'no IBAN' => [str_replace('IBAN AT61 1904 3002 3457 3201, ', '', self::LETTER), self::SENDER, '110,00 Euro'];
        yield 'checksum wrong (OCR misread)' => [str_replace('3201', '3207', self::LETTER), self::SENDER, '110,00 Euro'];
        yield 'two different IBANs' => [self::LETTER."\nOder: DE89 3704 0044 0532 0130 00.", self::SENDER, '110,00 Euro'];
        yield 'sender not in the letter' => [self::LETTER, 'BH Musterstadt', '110,00 Euro'];
        yield 'sender too long for EPC' => [str_replace(self::SENDER, str_repeat('Amt ', 20), self::LETTER), str_repeat('Amt ', 20), '110,00 Euro'];
    }

    #[DataProvider('noPayment')]
    public function testNoPaymentWhenIbanOrPayeeIsUncertain(string $letter, string $sender, ?string $amount): void
    {
        self::assertNull($this->extract($letter, $sender, $amount));
    }

    public function testSameIbanTwiceIsStillOne(): void
    {
        $payment = $this->extract(self::LETTER."\nNochmals: IBAN AT611904300234573201.");

        self::assertSame('AT611904300234573201', $payment?->iban);
    }

    public function testIbanSplitAcrossLinesIsFound(): void
    {
        $payment = $this->extract(str_replace('AT61 1904 3002 3457 3201', "AT61 1904 3002\n3457 3201", self::LETTER));

        self::assertSame('AT611904300234573201', $payment?->iban);
    }

    public function testFollowingWordIsNotPartOfTheIban(): void
    {
        $payment = $this->extract(str_replace('3201, Verwendungszweck', '3201 BIC BKAUATWW, Verwendungszweck', self::LETTER));

        self::assertSame('AT611904300234573201', $payment?->iban);
    }

    /**
     * @return iterable<string, array{string, ?string, ?int}>
     */
    public static function amounts(): iterable
    {
        yield 'one amount, answer agrees' => [self::LETTER, '110,00 Euro', 11000];
        yield 'answer names no amount' => [self::LETTER, null, null];
        yield 'answer names another amount' => [self::LETTER, '100,00 Euro', null];
        yield 'two amounts in the letter' => [self::LETTER."\nDie Verfahrenskosten betragen 10,00 Euro.", '110,00 Euro', null];
        yield 'thousands separator' => [str_replace('110,00 Euro', '1.245,00 Euro', self::LETTER), '1.245,00 Euro', 124500];
        yield 'euro sign before the number' => [str_replace('110,00 Euro', '€ 110,00', self::LETTER), '€ 110,00', 11000];
    }

    #[DataProvider('amounts')]
    public function testAmountOnlyWhenUnambiguous(string $letter, ?string $answered, ?int $expected): void
    {
        self::assertSame($expected, $this->extract($letter, amount: $answered)?->amountCents);
    }

    /**
     * @return iterable<string, array{string, ?string, ?string}>
     */
    public static function remittances(): iterable
    {
        yield 'stated with colon' => [self::LETTER, null, 'BHMS-VK-2026/48213'];
        yield 'only described' => [str_replace('Verwendungszweck: BHMS-VK-2026/48213.', 'Geben Sie als Verwendungszweck Ihre Steuernummer an.', self::LETTER), null, null];
        yield 'creditor reference' => [str_replace('BHMS-VK-2026/48213.', 'RF18 5390 0754 7034.', self::LETTER), 'RF18539007547034', null];
        yield 'two different values' => [self::LETTER."\nZahlungsreferenz: 4711.", null, null];
    }

    #[DataProvider('remittances')]
    public function testRemittanceOnlyWhenTheLetterStatesIt(string $letter, ?string $reference, ?string $text): void
    {
        $payment = $this->extract($letter);

        self::assertNotNull($payment);
        self::assertSame($reference, $payment->reference);
        self::assertSame($text, $payment->text);
    }

    private function extract(string $letter, string $sender = self::SENDER, ?string $amount = '110,00 Euro'): ?PaymentDetails
    {
        $explanation = new LetterExplanation($sender, 'Strafverfügung', 'Zusammenfassung.', true, ['Bezahle.'], null, $amount, [], []);

        return (new PaymentDetailsExtractor())->extract($letter, $explanation);
    }
}
