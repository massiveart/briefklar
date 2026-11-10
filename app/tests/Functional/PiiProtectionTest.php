<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FailingDetector;
use App\Tests\Support\PdfFixtureFactory;
use App\Tests\Support\ScriptedPlatform;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Milestone M2: the real pii-shield sits between the app and the provider.
 *
 * Every test goes through the whole app (upload, extraction, agent with pii-shield processor) and
 * inspects what ScriptedPlatform recorded, i.e. exactly what a real provider would have received.
 * Expected PII and must-survive values come from fixtures/letters/*.expected.json.
 */
final class PiiProtectionTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function uploads(): iterable
    {
        foreach (['01-strafverfuegung', '02-finanzamt-vorschreibung'] as $letter) {
            foreach (['text-pdf', 'scanned-pdf'] as $kind) {
                foreach ([false => 'standard', true => 'easy'] as $easy => $style) {
                    yield \sprintf('%s, %s, %s', $letter, $kind, $style) => [$letter, $kind, (bool) $easy];
                }
            }
        }
    }

    #[DataProvider('uploads')]
    public function testNoPiiReachesTheProvider(string $letter, string $kind, bool $easyLanguage): void
    {
        $client = self::createClient();
        $this->upload($client, self::document($letter, $kind), $easyLanguage);

        self::assertResponseIsSuccessful();
        $requests = self::getContainer()->get(ScriptedPlatform::class)->requests();
        self::assertCount(1, $requests);
        foreach (self::piiValues($letter) as $value) {
            self::assertStringNotContainsString($value, $requests[0], \sprintf('"%s" was sent to the provider.', $value));
        }
        self::assertStringContainsString('⟦', $requests[0]);
    }

    /**
     * Over-redaction would make the explanation useless. Only checked on the text layer:
     * OCR output may differ in details that have nothing to do with redaction.
     */
    #[DataProvider('letters')]
    public function testValuesNeededForTheExplanationReachTheProvider(string $letter): void
    {
        $client = self::createClient();
        $this->upload($client, self::document($letter, 'text-pdf'));

        $request = self::getContainer()->get(ScriptedPlatform::class)->requests()[0];
        foreach (self::expectations($letter)['must_survive'] as $value) {
            self::assertStringContainsString($value, $request);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function letters(): iterable
    {
        yield '01' => ['01-strafverfuegung'];
        yield '02' => ['02-finanzamt-vorschreibung'];
    }

    public function testResultPageShowsTheOriginalValuesAgain(): void
    {
        $client = self::createClient();
        // The canned answer refers to ⟦IBAN_1⟧ and ⟦FILE_NUMBER_1⟧, like a real model would.
        $this->upload($client, self::document('01-strafverfuegung', 'text-pdf'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.actions', 'AT61 1904 3002 3457 3201');
        self::assertSelectorTextContains('.actions', 'BHMS-VK-2026/48213');
        self::assertSelectorNotExists('.placeholder-note');
        self::assertStringNotContainsString('⟦', (string) $client->getResponse()->getContent());
    }

    public function testFlagsPlaceholdersTheModelInvented(): void
    {
        $client = self::createClient();
        // Letter 02 has no IBAN, but the canned answer mentions ⟦IBAN_1⟧.
        $this->upload($client, self::document('02-finanzamt-vorschreibung', 'text-pdf'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.placeholder-note', 'Platzhalter');
    }

    public function testNothingIsSentWhenRedactionFails(): void
    {
        $client = self::createClient();
        $this->upload($client, self::document('01-strafverfuegung', 'text-pdf', FailingDetector::MARKER));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Die Erklärung hat gerade nicht geklappt');
        self::assertSame([], self::getContainer()->get(ScriptedPlatform::class)->requests());
    }

    private function upload(KernelBrowser $client, string $path, bool $easyLanguage = false): void
    {
        $form = $client->request('GET', '/de')->selectButton('Brief erklären')->form();
        $file = $form['letter_upload[letter]'];
        self::assertInstanceOf(FileFormField::class, $file);
        $file->upload($path);
        if ($easyLanguage) {
            $checkbox = $form['letter_upload[easyLanguage]'];
            self::assertInstanceOf(ChoiceFormField::class, $checkbox);
            $checkbox->tick();
        }
        $client->submit($form);
    }

    private static function document(string $letter, string $kind, string $appendix = ''): string
    {
        $text = PdfFixtureFactory::letter($letter).$appendix;
        if ('text-pdf' === $kind) {
            return PdfFixtureFactory::toTempFile(PdfFixtureFactory::textPdf($text), '.pdf');
        }

        foreach (['tesseract', 'pdftoppm'] as $binary) {
            if (null === (new ExecutableFinder())->find($binary)) {
                self::markTestSkipped(\sprintf('"%s" is not installed; run the tests in the app container.', $binary));
            }
        }

        return PdfFixtureFactory::toTempFile(PdfFixtureFactory::scannedPdf($text), '.pdf');
    }

    /**
     * Each line on its own: a multi-line address must not leak line by line either.
     *
     * @return list<string>
     */
    private static function piiValues(string $letter): array
    {
        $values = [];
        foreach (self::expectations($letter)['pii'] as $entity) {
            foreach (explode("\n", $entity['value']) as $line) {
                $values[] = trim($line);
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @return array{pii: list<array{type: string, value: string}>, must_survive: list<string>}
     */
    private static function expectations(string $letter): array
    {
        $json = file_get_contents(\dirname(__DIR__, 3).'/fixtures/letters/'.$letter.'.expected.json');
        self::assertIsString($json);
        /** @var array{pii: list<array{type: string, value: string}>, must_survive: list<string>} $expectations */
        $expectations = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        return $expectations;
    }
}
