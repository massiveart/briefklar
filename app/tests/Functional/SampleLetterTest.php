<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Sample letters let a stranger try Clarifile without a letter at hand (README quickstart, video).
 */
final class SampleLetterTest extends WebTestCase
{
    public function testUploadPageOffersTheRegexSampleLetters(): void
    {
        $client = self::createClient();
        $client->request('GET', '/de');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.samples', 'Strafverfügung');
        self::assertSelectorExists('.samples a[href="/samples/text/01-strafverfuegung.pdf"]');
        self::assertSelectorExists('.samples a[href="/samples/scan/02-finanzamt-vorschreibung.pdf"]');
        // Only model-based detection redacts letter 03; it is off by default.
        self::assertSelectorNotExists('.samples a[href*="03-anonymverfuegung"]');
    }

    public function testDownloadsATextPdf(): void
    {
        $client = self::createClient();
        $client->request('GET', '/samples/text/01-strafverfuegung.pdf');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename=clarifile-sample-01-strafverfuegung.pdf');
        self::assertStringStartsWith('%PDF-', (string) $client->getResponse()->getContent());
    }

    public function testDownloadsAScan(): void
    {
        if (null === (new ExecutableFinder())->find('pdftoppm')) {
            self::markTestSkipped('"pdftoppm" is not installed; run the tests in the app container.');
        }

        $client = self::createClient();
        $client->request('GET', '/samples/scan/01-strafverfuegung.pdf');

        self::assertResponseIsSuccessful();
        $pdf = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('/Subtype /Image', $pdf);
        self::assertStringNotContainsString('/Font', $pdf);
    }

    public function testUnknownOrHiddenLettersAreNotFound(): void
    {
        $client = self::createClient();
        foreach (['/samples/text/99-unknown.pdf', '/samples/text/03-anonymverfuegung.pdf', '/samples/other/01-strafverfuegung.pdf'] as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
    }
}
