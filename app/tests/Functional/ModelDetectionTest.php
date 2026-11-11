<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\PdfFixtureFactory;
use App\Tests\Support\ScriptedDetectionModel;
use App\Tests\Support\ScriptedPlatform;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\FileFormField;

/**
 * Milestone M3: model-based PII detection (PII_MODEL_DETECTION) on top of the regex detectors.
 *
 * Letter 03 (/fixtures/letters, "detection": "model") names the recipient without salutation and gives a one-line address, which the
 * regex detectors cannot find. ScriptedDetectionModel stands in for the local Ollama.
 */
final class ModelDetectionTest extends WebTestCase
{
    private const array PII = ['Lukas Testmann', 'Gartengasse 5', '8990 Beispieldorf'];

    private mixed $previousEnv = null;
    private mixed $previousServer = null;

    protected function setUp(): void
    {
        $this->previousEnv = $_ENV['PII_MODEL_DETECTION'] ?? null;
        $this->previousServer = $_SERVER['PII_MODEL_DETECTION'] ?? null;
    }

    protected function tearDown(): void
    {
        // Restore, not unset: .env is loaded once per test run, later kernels need the variable.
        $_ENV['PII_MODEL_DETECTION'] = $this->previousEnv;
        $_SERVER['PII_MODEL_DETECTION'] = $this->previousServer;
        parent::tearDown();
    }

    public function testOffByDefaultTheModelIsNotAsked(): void
    {
        $client = self::createClient();
        $this->upload($client, PdfFixtureFactory::letter('03-anonymverfuegung'));

        self::assertResponseIsSuccessful();
        self::assertSame([], self::getContainer()->get(ScriptedDetectionModel::class)->requests());
        // Documents the gap model detection closes: the patterns alone miss this name.
        self::assertStringContainsString('Lukas Testmann', self::getContainer()->get(ScriptedPlatform::class)->requests()[0]);
    }

    public function testOnNoPiiReachesTheProviderAndTheAnswerIsRestored(): void
    {
        $client = $this->clientWithModelDetection();
        $this->upload($client, PdfFixtureFactory::letter('03-anonymverfuegung'));

        self::assertCount(1, self::getContainer()->get(ScriptedDetectionModel::class)->requests());
        $request = self::getContainer()->get(ScriptedPlatform::class)->requests()[0];
        foreach (self::PII as $value) {
            self::assertStringNotContainsString($value, $request, \sprintf('"%s" was sent to the provider.', $value));
        }
        self::assertStringContainsString('⟦PERSON_1⟧', $request);
        foreach (['48,00 Euro', '§ 4 Abs. 1 MusterParkG', 'binnen vier Wochen ab Ausstellung', 'Magistrat der Stadt Musterhausen'] as $kept) {
            self::assertStringContainsString($kept, $request);
        }

        self::assertResponseIsSuccessful();
        // The canned answer says ⟦PERSON_1⟧; the user sees the name again.
        self::assertSelectorTextContains('h1', 'Lukas Testmann');
    }

    public function testOnNothingIsSentWhenTheDetectionModelFails(): void
    {
        $client = $this->clientWithModelDetection();
        $this->upload($client, PdfFixtureFactory::letter('03-anonymverfuegung')."\n".ScriptedDetectionModel::UNREACHABLE_MARKER);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Die Erklärung hat gerade nicht geklappt');
        self::assertSame([], self::getContainer()->get(ScriptedPlatform::class)->requests());
    }

    private function clientWithModelDetection(): KernelBrowser
    {
        // Read at runtime by SwitchableModelDetector (%env(bool:PII_MODEL_DETECTION)%).
        $_ENV['PII_MODEL_DETECTION'] = $_SERVER['PII_MODEL_DETECTION'] = '1';

        return self::createClient();
    }

    private function upload(KernelBrowser $client, string $text): void
    {
        $form = $client->request('GET', '/de')->selectButton('Brief erklären')->form();
        $file = $form['letter_upload[letter]'];
        self::assertInstanceOf(FileFormField::class, $file);
        $file->upload(PdfFixtureFactory::toTempFile(PdfFixtureFactory::textPdf($text), '.pdf'));
        $client->submit($form);
    }
}
