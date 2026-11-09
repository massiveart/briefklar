<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\PdfFixtureFactory;
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

    public function testUploadingFixture01AsPdfShowsExtractedText(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $pdf = PdfFixtureFactory::toTempFile(PdfFixtureFactory::textPdf(PdfFixtureFactory::letter('01-strafverfuegung')), '.pdf');
        $form = $crawler->selectButton('Brief erklären')->form();
        self::attach($form, $pdf);
        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Erkannter Text');
        self::assertSelectorTextContains('.extracted-text', 'STRAFVERFÜGUNG');
        self::assertSelectorTextContains('.extracted-text', 'binnen zwei Wochen');
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

    private static function attach(Form $form, string $path): void
    {
        $field = $form['letter_upload[letter]'];
        self::assertInstanceOf(FileFormField::class, $field);
        $field->upload($path);
    }
}
