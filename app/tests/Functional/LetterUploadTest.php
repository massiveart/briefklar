<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\PdfFixtureFactory;
use App\Tests\Support\ScriptedPlatform;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Form;

final class LetterUploadTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function preferredLanguages(): iterable
    {
        yield 'empty header' => ['', '/de'];
        yield 'german' => ['de-AT,de;q=0.9,en;q=0.8', '/de'];
        yield 'english' => ['en-US,en;q=0.9', '/en'];
        yield 'english before german' => ['en-GB,de;q=0.5', '/en'];
        yield 'unsupported falls back to german' => ['fr-FR,fr;q=0.9', '/de'];
    }

    #[DataProvider('preferredLanguages')]
    public function testRootRedirectsToPreferredLanguage(string $acceptLanguage, string $expected): void
    {
        $client = self::createClient();
        // BrowserKit sends "en-us" by default, so the header is always set explicitly.
        $client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => $acceptLanguage]);

        self::assertResponseRedirects($expected);
    }

    public function testEnglishUploadPageRenders(): void
    {
        $client = self::createClient();
        $client->request('GET', '/en');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[lang=en]');
        self::assertSelectorTextContains('h1', 'Understand a letter');
        self::assertSelectorExists('a[hreflang=de][href="/de"]');
    }

    public function testUploadingFixture01OnEnglishPageExplainsInEnglish(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/en');
        $form = $crawler->selectButton('Explain letter')->form();
        self::attach($form, PdfFixtureFactory::toTempFile(PdfFixtureFactory::textPdf(PdfFixtureFactory::letter('01-strafverfuegung')), '.pdf'));
        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#deadline-heading', 'Deadline');
        // the deadline stays verbatim as written in the letter, so it can be checked against the original
        self::assertSelectorTextContains('.deadline__value', 'binnen zwei Wochen ab Zustellung');
        self::assertSelectorTextContains('.disclaimer', 'not legal advice');
        $requests = self::getContainer()->get(ScriptedPlatform::class)->requests();
        self::assertCount(1, $requests);
        self::assertStringContainsString('Answer in English.', $requests[0]);
    }

    public function testUploadPageRenders(): void
    {
        $client = self::createClient();
        $client->request('GET', '/de');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[lang=de]');
        self::assertSelectorTextContains('h1', 'Brief verstehen');
        self::assertSelectorExists('input[type=file][name="letter_upload[letter]"]');
        self::assertSelectorExists('a[hreflang=en][href="/en"]');
    }

    public function testUploadFormShowsProgressWhileTheLetterIsExplained(): void
    {
        $client = self::createClient();
        $client->request('GET', '/de');

        // The upload-progress Stimulus controller disables the button and reveals the status on submit.
        self::assertSelectorExists('form[data-controller="upload-progress"][data-action="submit->upload-progress#start"]');
        self::assertSelectorExists('form[data-upload-progress-busy-label-value="Brief wird erklärt …"]');
        self::assertSelectorExists('button[type=submit][data-upload-progress-target="submit"]');
        self::assertSelectorExists('[role=status][data-upload-progress-target="status"][hidden]');
        self::assertSelectorTextContains('.upload-status', 'bis zu einer Minute');
    }

    public function testUploadingFixture01AsPdfShowsExplanationWithDeadline(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/de');

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

    public function testEasyLanguageToggleIsOffByDefault(): void
    {
        $client = self::createClient();
        $this->uploadText($client, PdfFixtureFactory::letter('01-strafverfuegung'));

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.style-note');
    }

    public function testEasyLanguageToggleProducesEasyLanguageExplanation(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/de');
        $form = $crawler->selectButton('Brief erklären')->form();
        self::attach($form, PdfFixtureFactory::toTempFile(PdfFixtureFactory::textPdf(PdfFixtureFactory::letter('01-strafverfuegung')), '.pdf'));
        $field = $form['letter_upload[easyLanguage]'];
        self::assertInstanceOf(ChoiceFormField::class, $field);
        $field->tick();
        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.style-note', 'In Leichter Sprache');
        self::assertSelectorTextContains('.deadline__value', 'binnen zwei Wochen ab Zustellung');
        $requests = self::getContainer()->get(ScriptedPlatform::class)->requests();
        self::assertCount(1, $requests);
        self::assertStringContainsString('Leichte Sprache', $requests[0]);
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
        $crawler = $client->request('GET', '/de');

        $form = $crawler->selectButton('Brief erklären')->form();
        self::attach($form, PdfFixtureFactory::toTempFile('Nur Text, kein Brief.', '.txt'));
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Dieses Dateiformat wird nicht unterstützt');
    }

    public function testRejectsPdfWithoutReadableText(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/de');

        $form = $crawler->selectButton('Brief erklären')->form();
        self::attach($form, PdfFixtureFactory::toTempFile('%PDF-1.4 kaputt', '.pdf'));
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'keinen Text erkennen');
    }

    private function uploadText(KernelBrowser $client, string $text): void
    {
        $crawler = $client->request('GET', '/de');
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
