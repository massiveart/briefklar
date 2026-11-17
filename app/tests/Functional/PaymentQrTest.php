<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\PdfFixtureFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\FileFormField;

/**
 * The payment QR code (PAYMENT_QR) comes from the letter text and is off by default.
 * Fixture 01 states one IBAN, one amount and a "Verwendungszweck"; letter 02 has no IBAN.
 */
final class PaymentQrTest extends WebTestCase
{
    private mixed $previousEnv = null;
    private mixed $previousServer = null;

    protected function setUp(): void
    {
        $this->previousEnv = $_ENV['PAYMENT_QR'] ?? null;
        $this->previousServer = $_SERVER['PAYMENT_QR'] ?? null;
    }

    protected function tearDown(): void
    {
        // Restore, not unset: .env is loaded once per test run, later kernels need the variable.
        $_ENV['PAYMENT_QR'] = $this->previousEnv;
        $_SERVER['PAYMENT_QR'] = $this->previousServer;
        parent::tearDown();
    }

    public function testShowsTheCodeWithTheDataFromTheLetter(): void
    {
        $client = $this->client(true);
        $this->upload($client, '01-strafverfuegung');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.payment-qr svg');
        self::assertSelectorTextContains('.payment__iban', 'AT61 1904 3002 3457 3201');
        self::assertSelectorTextContains('.payment__details', 'Bezirkshauptmannschaft Musterstadt');
        self::assertSelectorTextContains('.payment__details', '110,00 Euro');
        self::assertSelectorTextContains('.payment__details', 'BHMS-VK-2026/48213');
        self::assertSelectorTextContains('.payment .warning', 'Vergleiche Empfänger, IBAN und Betrag');
    }

    public function testNoCodeWhenTheLetterHasNoIban(): void
    {
        $client = $this->client(true);
        $this->upload($client, '02-finanzamt-vorschreibung');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.payment');
    }

    public function testOffByDefault(): void
    {
        $client = $this->client(false);
        $this->upload($client, '01-strafverfuegung');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.payment');
    }

    private function client(bool $paymentQr): KernelBrowser
    {
        // Read at runtime by LetterExplainer (%env(bool:PAYMENT_QR)%).
        $_ENV['PAYMENT_QR'] = $_SERVER['PAYMENT_QR'] = $paymentQr ? '1' : '0';

        return self::createClient();
    }

    private function upload(KernelBrowser $client, string $letter): void
    {
        $form = $client->request('GET', '/de')->selectButton('Brief erklären')->form();
        $file = $form['letter_upload[letter]'];
        self::assertInstanceOf(FileFormField::class, $file);
        $file->upload(PdfFixtureFactory::toTempFile(PdfFixtureFactory::textPdf(PdfFixtureFactory::letter($letter)), '.pdf'));
        $client->submit($form);
    }
}
