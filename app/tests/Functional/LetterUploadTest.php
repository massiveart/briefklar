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
        yield 'empty header' => ['', '/en'];
        yield 'german' => ['de-AT,de;q=0.9,en;q=0.8', '/de'];
        yield 'english' => ['en-US,en;q=0.9', '/en'];
        yield 'english before german' => ['en-GB,de;q=0.5', '/en'];
        yield 'unsupported falls back to english' => ['fr-FR,fr;q=0.9', '/en'];
    }

    #[DataProvider('preferredLanguages')]
    public function testRootRedirectsToPreferredLanguage(string $acceptLanguage, string $expected): void
    {
        $client = self::createClient();
        // BrowserKit sends "en-us" by default, so the header is always set explicitly.
        $client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => $acceptLanguage]);

        self::assertResponseRedirects($expected);
    }

    public function testShowsTheClarifileBrand(): void
    {
        self::createClient()->request('GET', '/en');

        self::assertSelectorTextContains('title', 'Clarifile');
        self::assertSelectorExists('header img[alt="Clarifile"]');
    }

    public function testFrameHasNavigationAndFooter(): void
    {
        $crawler = self::createClient()->request('GET', '/de');

        foreach (['#how-it-works', '#privacy', '#mcp'] as $anchor) {
            self::assertSelectorExists(\sprintf('header a[href$="%s"]', $anchor));
        }
        self::assertSelectorExists('header a[href="https://github.com/massiveart/clarifile"]');
        self::assertSelectorTextContains('.site-footer', 'keine Rechtsberatung');
        self::assertSame('EN', trim($crawler->filter('.lang-switch > *')->first()->text()));
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function landingCopy(): iterable
    {
        yield 'german' => ['/de', 'Das Sprachmodell, das deinen Brief erklärt, sieht weder Namen noch Adressen noch Kontonummern.', 'Klarheit statt Amtsdeutsch', 'Lässt sich in der Konfiguration einschalten.'];
        yield 'english' => ['/en', 'The language model that explains your letter never sees names, addresses or account numbers.', 'Clarity instead of officialese', 'Can be switched on in the configuration.'];
    }

    #[DataProvider('landingCopy')]
    public function testLandingExplainsTheFlow(string $page, string $privacyPromise, string $whatYouGet, string $qrOptional): void
    {
        self::createClient()->request('GET', $page);

        self::assertSelectorTextContains('.hero', $privacyPromise);
        self::assertSelectorCount(4, '#how-it-works .step');
        self::assertSelectorCount(1, '#how-it-works .where--model');
        self::assertSelectorTextContains('#privacy', 'Max Muster');
        self::assertSelectorTextContains('#privacy', '⟦PERSON_1⟧');
        self::assertSelectorTextContains('#privacy', '⟦IBAN_1⟧');
        self::assertSelectorTextContains('#what-you-get h2', $whatYouGet);
        self::assertSelectorTextContains('#what-you-get .tag', 'optional');
        self::assertSelectorTextContains('#what-you-get', $qrOptional);
        foreach (['explain_letter_file', 'explain_letter', 'redact_text', 'claude mcp add'] as $text) {
            self::assertSelectorTextContains('#mcp', $text);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function piiShieldCopy(): iterable
    {
        yield 'german' => ['/de', 'Ein eigenes Paket'];
        yield 'english' => ['/en', 'A package of its own'];
    }

    #[DataProvider('piiShieldCopy')]
    public function testLandingPresentsPiiShieldAsItsOwnPackage(string $page, string $packageTitle): void
    {
        $crawler = self::createClient()->request('GET', $page);

        self::assertSelectorTextContains('#pii-shield h2', 'PII Shield');
        self::assertSelectorCount(3, '#pii-shield .feature');
        self::assertSelectorTextContains('#pii-shield', $packageTitle);
        foreach (['clarifile/pii-shield', 'InputProcessor', 'OutputProcessor', 'IBAN'] as $text) {
            self::assertSelectorTextContains('#pii-shield', $text);
        }
        self::assertSame(
            'https://github.com/massiveart/clarifile/tree/main/packages/pii-shield',
            $crawler->filter('#pii-shield a.pii-shield__link')->attr('href'),
        );
        // Placed between "what you get" and the MCP section.
        self::assertSame(['how-it-works', 'privacy', 'what-you-get', 'pii-shield', 'mcp'], $crawler->filter('main section[id]')->each(static fn ($s) => $s->attr('id')));
    }

    #[DataProvider('pages')]
    public function testDropzoneWrapsTheNativeInput(string $page, string $submit): void
    {
        $crawler = self::createClient()->request('GET', $page);

        $input = $crawler->filter('.dropzone[data-controller~="dropzone"] input[type=file][name="letter_upload[letter]"]');
        self::assertCount(1, $input);
        self::assertSame('required', $input->attr('required'));
        self::assertSame('application/pdf,image/png,image/jpeg,image/tiff', $input->attr('accept'));
        // Without JavaScript the native input stays visible and usable.
        self::assertNull($input->attr('hidden'));
        self::assertStringNotContainsString('display', (string) $input->attr('style'));
        self::assertStringNotContainsString('visually-hidden', (string) $input->attr('class'));
        self::assertSelectorExists('.dropzone label[for="letter_upload_letter"]');
        self::assertCount(1, $crawler->selectButton($submit));
    }

    #[DataProvider('pages')]
    public function testDropzoneOnlyHoldsTheFileChoice(string $page, string $submit): void
    {
        $crawler = self::createClient()->request('GET', $page);

        // Options and the submit button sit below the zone, not inside it.
        self::assertSelectorNotExists('.dropzone input[type=checkbox]');
        self::assertSelectorNotExists('.dropzone button[type=submit]');
        self::assertSelectorExists('.upload-actions input[type=checkbox][name="letter_upload[easyLanguage]"]');
        self::assertCount(1, $crawler->filter('.upload-actions button[type=submit]')->reduce(static fn ($b): bool => trim($b->text()) === $submit));
    }

    public function testSampleLettersAreCollapsedByDefault(): void
    {
        $crawler = self::createClient()->request('GET', '/de');

        $details = $crawler->filter('details.samples');
        self::assertCount(1, $details);
        self::assertNull($details->attr('open'));
        self::assertSelectorTextContains('details.samples > summary', 'Kein Brief zur Hand?');
        self::assertSelectorExists('details.samples a[href="/samples/scan/01-strafverfuegung.pdf"]');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pages(): iterable
    {
        yield 'german' => ['/de', 'Brief erklären'];
        yield 'english' => ['/en', 'Explain letter'];
    }

    public function testEnglishUploadPageRenders(): void
    {
        $client = self::createClient();
        $client->request('GET', '/en');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[lang=en]');
        self::assertSelectorTextContains('h1', 'Official letters, clearly explained.');
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
        // the deadline stays verbatim as written in the letter, so it can be checked against the original;
        // the sentence it came from is shown apart, as a quote, not as part of the deadline
        self::assertSelectorTextSame('.deadline__value', 'binnen zwei Wochen ab Zustellung');
        self::assertSelectorTextContains('.deadline__source', 'In the letter');
        self::assertSelectorTextContains('.deadline__source', 'Bitte überweisen Sie den Betrag');
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
        self::assertSelectorTextContains('h1', 'Behördenbriefe, klar erklärt.');
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

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function stepLabels(): iterable
    {
        yield 'german' => ['/de', 'Schritt {step} von {total}: {name}'];
        yield 'english' => ['/en', 'Step {step} of {total}: {name}'];
    }

    #[DataProvider('stepLabels')]
    public function testProgressStepsAreAnnouncedToScreenReaders(string $page, string $stepLabel): void
    {
        self::createClient()->request('GET', $page);

        // The step pills are decoration (aria-hidden); the controller writes the current step into a
        // visually hidden text inside the status region, so screen readers hear it change.
        self::assertSelectorExists('.upload-steps[aria-hidden="true"]');
        self::assertSelectorExists('[role=status] .visually-hidden[data-upload-progress-target="announcement"]');
        self::assertSelectorExists(\sprintf('form[data-upload-progress-step-label-value="%s"]', $stepLabel));
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
        self::assertSelectorNotExists('.date-note');
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
        // ISO date from the model, shown the way the letter writes dates
        self::assertSelectorTextSame('.deadline__value', '30.11.2026');
        self::assertSelectorTextContains('.deadline .warning', 'nicht im Brief gefunden');
    }

    public function testHidesTheLetterQuoteWhenTheModelReworded(): void
    {
        $client = self::createClient();
        // Canned answer 01 quotes "Bitte überweisen Sie den Betrag …"; this letter words it differently,
        // as if the model had rewritten the sentence (issue #78).
        $letter = str_replace('Bitte überweisen Sie den Betrag', 'Überweisen Sie bitte den Betrag', PdfFixtureFactory::letter('01-strafverfuegung'));
        $this->uploadText($client, $letter);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.deadline__value', 'binnen zwei Wochen ab Zustellung');
        self::assertSelectorNotExists('.deadline__source');
        self::assertSelectorNotExists('.deadline .warning');
    }

    public function testFlagsDatesInTheTextThatAreNotInTheLetter(): void
    {
        $client = self::createClient();
        // Canned answer 02: "Überweise 1.245,00 Euro bis 30.11.2026 …", the letter says 15.11.2026.
        $this->uploadText($client, PdfFixtureFactory::letter('02-finanzamt-vorschreibung'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.date-note', '30.11.2026');
        self::assertSelectorTextContains('.date-note', 'nicht im Brief');
        // The model text itself stays as it is.
        self::assertSelectorTextContains('.actions', 'bis 30.11.2026');
    }

    public function testShowsStepsEvenWhenTheModelSaysNothingIsRequired(): void
    {
        $client = self::createClient();
        // Canned answer 02: actionRequired false next to a payment step.
        $this->uploadText($client, PdfFixtureFactory::letter('02-finanzamt-vorschreibung'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.actions', 'Überweise 1.245,00 Euro');
        self::assertSelectorTextNotContains('main', 'Du musst nichts tun');
    }

    public function testShowsFriendlyErrorWhenProviderFails(): void
    {
        $client = self::createClient();
        $this->uploadText($client, 'Sehr geehrte Damen und Herren, dieser synthetische Testbrief hat keine vorbereitete Antwort des Test-Providers.');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Die Erklärung hat gerade nicht geklappt');
    }

    public function testShowsFriendlyErrorWhenTheModelTakesTooLong(): void
    {
        $client = self::createClient();
        $this->uploadText($client, PdfFixtureFactory::letter('01-strafverfuegung')."\n".ScriptedPlatform::TIMEOUT_MARKER);

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

    public function testServerErrorIsLinkedToTheFileInput(): void
    {
        // The dropzone renders the widget on its own; screen readers still need the error tied to the input.
        $client = self::createClient();
        $form = $client->request('GET', '/de')->selectButton('Brief erklären')->form();
        self::attach($form, PdfFixtureFactory::toTempFile('Nur Text, kein Brief.', '.txt'));
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        $input = $client->getCrawler()->filter('input[name="letter_upload[letter]"]');
        self::assertSame('true', $input->attr('aria-invalid'));
        self::assertStringContainsString('letter_upload_letter_error1', (string) $input->attr('aria-describedby'));
        self::assertSelectorExists('#letter_upload_letter_error1');
    }

    /**
     * @return iterable<string, array{string, string, string, string, string}>
     */
    public static function honestCopy(): iterable
    {
        yield 'german' => ['/de', 'Die KI sieht', 'Was das Sprachmodell zu sehen bekommt', 'explain_letter_file', 'Bitte nur einen Brief auf einmal.'];
        yield 'english' => ['/en', 'The AI only sees', 'What the language model gets to see', 'explain_letter_file', 'Please drop one letter at a time.'];
    }

    /**
     * With PII_MODEL_DETECTION a local model reads the unredacted letter, and explain_letter hands the
     * letter to the assistant: the copy only promises what holds in every configuration.
     */
    #[DataProvider('honestCopy')]
    public function testCopyOnlyPromisesWhatHolds(string $page, string $tooBroad, string $privacyTitle, string $mcpTool, string $oneFile): void
    {
        $crawler = self::createClient()->request('GET', $page);

        self::assertStringNotContainsString($tooBroad, $crawler->filter('#how-it-works')->text());
        self::assertSelectorTextContains('#privacy h2', $privacyTitle);
        self::assertSelectorTextContains('#mcp .mcp__text', $mcpTool);
        self::assertStringContainsString($oneFile, (string) $crawler->filter('.dropzone')->attr('data-dropzone-messages-value'));
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

    public function testDecompressionBombEndsInAFriendlyMessage(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/de');

        // Issue #84: 300 MB of page content in a file of a few hundred kilobytes.
        $form = $crawler->selectButton('Brief erklären')->form();
        self::attach($form, PdfFixtureFactory::toTempFile(PdfFixtureFactory::decompressionBomb(300), '.pdf'));
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
