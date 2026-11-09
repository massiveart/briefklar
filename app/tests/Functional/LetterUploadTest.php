<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\PdfFixtureFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Form;

final class LetterUploadTest extends WebTestCase
{
    public function testUploadPageRenders(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Brief verstehen');
        self::assertSelectorExists('input[type=file][name="letter_upload[letter]"]');
    }

    public function testUploadingFixture01AsPdfShowsExplanationWithDeadline(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $pdf = PdfFixtureFactory::toTempFile(PdfFixtureFactory::textPdf(PdfFixtureFactory::letter('01-strafverfuegung')), '.pdf');
        $form = $crawler->selectButton('Brief erklären')->form();
        self::attach($form, $pdf);
        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.sender', 'Bezirkshauptmannschaft Musterstadt');
        self::assertSelectorTextContains('.deadline__value', 'binnen zwei Wochen ab Zustellung');
        self::assertSelectorNotExists('.deadline .warning');
        self::assertSelectorTextContains('.amount', '110,00 Euro');
        self::assertSelectorTextContains('.references', '§ 49 VStG');
        self::assertSelectorTextContains('.disclaimer', 'keine Rechtsberatung');
    }

    public function testFlagsDeadlineThatIsNotInTheLetter(): void
    {
        $client = self::createClient();
        $this->uploadText($client, PdfFixtureFactory::letter('02-finanzamt-vorschreibung'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.deadline__value', '2026-11-30');
        self::assertSelectorTextContains('.deadline .warning', 'nicht im Brief gefunden');
    }

    public function testShowsFriendlyErrorWhenProviderFails(): void
    {
        $client = self::createClient();
        $this->uploadText($client, 'Sehr geehrte Damen und Herren, dieser synthetische Testbrief hat keine vorbereitete Antwort des Test-Providers.');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Die Erklärung hat gerade nicht geklappt');
    }

    public function testRejectsUnsupportedFileType(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $form = $crawler->selectButton('Brief erklären')->form();
        self::attach($form, PdfFixtureFactory::toTempFile('Nur Text, kein Brief.', '.txt'));
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Dieses Dateiformat wird nicht unterstützt');
    }

    public function testRejectsPdfWithoutReadableText(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $form = $crawler->selectButton('Brief erklären')->form();
        self::attach($form, PdfFixtureFactory::toTempFile('%PDF-1.4 kaputt', '.pdf'));
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'keinen Text erkennen');
    }

    private function uploadText(KernelBrowser $client, string $text): void
    {
        $crawler = $client->request('GET', '/');
        $form = $crawler->selectButton('Brief erklären')->form();
        self::attach($form, PdfFixtureFactory::toTempFile(PdfFixtureFactory::textPdf($text), '.pdf'));
        $client->submit($form);
    }

    private static function attach(Form $form, string $path): void
    {
        $field = $form['letter_upload[letter]'];
        self::assertInstanceOf(FileFormField::class, $field);
        $field->upload($path);
    }
}
