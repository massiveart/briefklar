<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment;

use App\Payment\EpcPayload;
use App\Payment\PaymentDetails;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * EPC069-12 v3.0, section 2.2. IBANs are documentation examples.
 */
final class EpcPayloadTest extends TestCase
{
    private const string IBAN = 'AT611904300234573201';

    public function testFullTransferWithRemittanceText(): void
    {
        $payload = EpcPayload::of(new PaymentDetails('Bezirkshauptmannschaft Musterstadt', self::IBAN, 11000, text: 'BHMS-VK-2026/48213'));

        self::assertSame(
            "BCD\n002\n1\nSCT\n\nBezirkshauptmannschaft Musterstadt\nAT611904300234573201\nEUR110.00\n\n\nBHMS-VK-2026/48213",
            $payload,
        );
    }

    public function testStructuredReferenceGoesBeforeTheText(): void
    {
        $payload = EpcPayload::of(new PaymentDetails('Franz Mustermann', self::IBAN, 1230, reference: 'RF18539007547034'));

        self::assertSame("BCD\n002\n1\nSCT\n\nFranz Mustermann\nAT611904300234573201\nEUR12.30\n\nRF18539007547034", $payload);
    }

    public function testEndsAfterTheLastPopulatedElement(): void
    {
        $payload = EpcPayload::of(new PaymentDetails('Finanzamt Österreich', self::IBAN));

        self::assertSame("BCD\n002\n1\nSCT\n\nFinanzamt Österreich\nAT611904300234573201", $payload);
        self::assertStringEndsNotWith("\n", $payload);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function amounts(): iterable
    {
        yield 'one cent' => [1, 'EUR0.01'];
        yield 'whole euros' => [124500, 'EUR1245.00'];
        yield 'maximum' => [99_999_999_999, 'EUR999999999.99'];
    }

    #[DataProvider('amounts')]
    public function testFormatsTheAmount(int $cents, string $expected): void
    {
        self::assertSame($expected, explode("\n", EpcPayload::of(new PaymentDetails('Amt', self::IBAN, $cents)))[7]);
    }

    /**
     * @return iterable<string, array{PaymentDetails}>
     */
    public static function invalid(): iterable
    {
        yield 'zero amount' => [new PaymentDetails('Amt', self::IBAN, 0)];
        yield 'amount too large' => [new PaymentDetails('Amt', self::IBAN, 100_000_000_000)];
        yield 'name too long' => [new PaymentDetails(str_repeat('A', 71), self::IBAN)];
        yield 'empty name' => [new PaymentDetails('', self::IBAN)];
        yield 'text too long' => [new PaymentDetails('Amt', self::IBAN, text: str_repeat('x', 141))];
        yield 'reference too long' => [new PaymentDetails('Amt', self::IBAN, reference: 'RF'.str_repeat('1', 34))];
        yield 'line break in text' => [new PaymentDetails('Amt', self::IBAN, text: "eins\nzwei")];
    }

    #[DataProvider('invalid')]
    public function testRejectsWhatTheSpecificationDoesNotAllow(PaymentDetails $payment): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EpcPayload::of($payment);
    }

    public function testLongestAllowedFieldsStayWithinTheByteLimit(): void
    {
        // 70 + 34 + 140 characters of multi-byte text would exceed 331 bytes.
        $this->expectException(\InvalidArgumentException::class);
        EpcPayload::of(new PaymentDetails(str_repeat('Ö', 70), self::IBAN, 1, text: str_repeat('Ü', 140)));
    }

    public function testReferenceAndTextAreExclusive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PaymentDetails('Amt', self::IBAN, reference: 'RF18539007547034', text: 'Rechnung');
    }
}
