<?php

declare(strict_types=1);

namespace App\Tests\Integration\Mcp;

use App\Explanation\ExplanationTimeLimit;
use App\Mcp\LetterTools;
use App\Tests\Support\FailingDetector;
use App\Tests\Support\PdfFixtureFactory;
use App\Tests\Support\ScriptedPlatform;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Notification\ProgressNotification;
use Mcp\Server\ClientGateway;
use Mcp\Server\Protocol;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Suspension\NotificationSuspension;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The MCP tools against the real agent pipeline (pii-shield included) and ScriptedPlatform, which records
 * what a provider would receive. explain_letter_file must keep personal data away from the provider
 * and from the MCP client; expected PII comes from fixtures/letters/*.expected.json.
 */
final class LetterToolsTest extends KernelTestCase
{
    private string $folder;
    private mixed $previousEnv = null;
    private mixed $previousServer = null;

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/briefklar_mcp_letters_'.bin2hex(random_bytes(6));
        mkdir($this->folder);
        file_put_contents($this->folder.'/strafverfuegung.pdf', PdfFixtureFactory::textPdf(PdfFixtureFactory::letter('01-strafverfuegung')));
        file_put_contents($this->folder.'/notiz.txt', 'keine Datei für Briefe');

        $this->previousEnv = $_ENV['MCP_LETTER_DIR'] ?? null;
        $this->previousServer = $_SERVER['MCP_LETTER_DIR'] ?? null;
        $_ENV['MCP_LETTER_DIR'] = $_SERVER['MCP_LETTER_DIR'] = $this->folder;
    }

    protected function tearDown(): void
    {
        array_map('unlink', [$this->folder.'/strafverfuegung.pdf', $this->folder.'/notiz.txt']);
        rmdir($this->folder);
        // Restore, not unset: .env is loaded once per test run, later kernels need the variable.
        $_ENV['MCP_LETTER_DIR'] = $this->previousEnv;
        $_SERVER['MCP_LETTER_DIR'] = $this->previousServer;
        parent::tearDown();
    }

    public function testExplainLetterFileKeepsPersonalDataFromProviderAndClient(): void
    {
        $result = $this->tools()->explainLetterFile('strafverfuegung.pdf');

        $requests = self::getContainer()->get(ScriptedPlatform::class)->requests();
        self::assertCount(1, $requests);
        $answer = json_encode($result, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        foreach (self::piiValues('01-strafverfuegung') as $value) {
            self::assertStringNotContainsString($value, $requests[0], \sprintf('"%s" was sent to the provider.', $value));
            self::assertStringNotContainsString($value, $answer, \sprintf('"%s" was returned to the MCP client.', $value));
        }

        // The canned answer refers to ⟦IBAN_1⟧ and ⟦FILE_NUMBER_1⟧: they stay, and they are ours, not invented.
        self::assertStringContainsString('⟦IBAN_1⟧', $answer);
        self::assertSame([], $result['checks']['inventedPlaceholders']);
        self::assertSame('110,00 Euro', $result['explanation']['amount']);
        self::assertTrue($result['checks']['deadlineStatedInLetter']);
        self::assertSame('steps', $result['checks']['actionAdvice']);
    }

    public function testExplainLetterRestoresTheValuesTheClientAlreadyHas(): void
    {
        $result = $this->tools()->explainLetter(PdfFixtureFactory::letter('01-strafverfuegung'));

        $answer = json_encode($result, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('AT61 1904 3002 3457 3201', $answer);
        self::assertStringNotContainsString('⟦', $answer);
        foreach (self::piiValues('01-strafverfuegung') as $value) {
            self::assertStringNotContainsString($value, self::getContainer()->get(ScriptedPlatform::class)->requests()[0]);
        }
    }

    public function testRaisesPhpsTimeLimitLikeTheWebUpload(): void
    {
        // Over HTTP, PHP's 30 s default cut off a local model (seen with Claude Code against /mcp).
        try {
            $this->tools()->explainLetter(PdfFixtureFactory::letter('01-strafverfuegung'));
            $expected = self::getContainer()->get(ExplanationTimeLimit::class)->seconds();
            self::assertSame((string) $expected, \ini_get('max_execution_time'));
            self::assertGreaterThan(30, $expected);
        } finally {
            set_time_limit(0); // the CLI default, for the following tests
        }
    }

    public function testReportsProgressBetweenTheSteps(): void
    {
        // OCR plus a local model take about a minute, the default request timeout of the MCP Inspector.
        // The SDK's ClientGateway suspends the fiber per notification so the transport can stream it.
        $session = self::createStub(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());
        $session->method('get')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => Protocol::SESSION_ACTIVE_REQUEST_META === $key ? ['progressToken' => 'letter-1'] : $default);
        $tools = $this->tools();

        $fiber = new \Fiber(static fn (): array => $tools->explainLetterFile('strafverfuegung.pdf', client: new ClientGateway($session)));
        $sent = [];
        for ($suspended = $fiber->start(); !$fiber->isTerminated(); $suspended = $fiber->resume()) {
            self::assertInstanceOf(NotificationSuspension::class, $suspended);
            self::assertInstanceOf(ProgressNotification::class, $suspended->notification);
            $sent[] = [$suspended->notification->progressToken, $suspended->notification->progress, $suspended->notification->total, $suspended->notification->message];
        }

        self::assertSame([
            ['letter-1', 0.0, 2.0, 'Reading the letter'],
            ['letter-1', 1.0, 2.0, 'Redacting personal data and explaining the letter'],
        ], $sent);
        self::assertSame('110,00 Euro', $fiber->getReturn()['explanation']['amount']);
    }

    public function testRedactTextReturnsPlaceholdersAndTypesOnly(): void
    {
        $result = $this->tools()->redactText('Herr Max Mustermann, IBAN AT61 1904 3002 3457 3201');

        self::assertSame('Herr ⟦PERSON_1⟧, IBAN ⟦IBAN_1⟧', $result['redactedText']);
        self::assertEqualsCanonicalizing(['PERSON', 'IBAN'], $result['types']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedPaths(): iterable
    {
        yield 'outside the folder' => ['../../etc/passwd'];
        yield 'not a letter type' => ['notiz.txt'];
        yield 'missing' => ['fehlt.pdf'];
    }

    #[DataProvider('refusedPaths')]
    public function testRefusedPathsComeBackAsToolErrors(string $path): void
    {
        try {
            $this->tools()->explainLetterFile($path);
            self::fail('Expected a ToolCallException.');
        } catch (ToolCallException $e) {
            self::assertStringNotContainsString($this->folder, $e->getMessage());
        }
        self::assertSame([], self::getContainer()->get(ScriptedPlatform::class)->requests());
    }

    public function testNothingIsSentWhenRedactionFails(): void
    {
        $this->expectException(ToolCallException::class);
        try {
            $this->tools()->explainLetter(PdfFixtureFactory::letter('01-strafverfuegung').FailingDetector::MARKER);
        } finally {
            self::assertSame([], self::getContainer()->get(ScriptedPlatform::class)->requests());
        }
    }

    public function testRejectsUnknownStyle(): void
    {
        $this->expectException(ToolCallException::class);
        $this->tools()->explainLetter('Ein Brief.', style: 'poetry');
    }

    private function tools(): LetterTools
    {
        self::bootKernel();

        return self::getContainer()->get(LetterTools::class);
    }

    /**
     * @return list<string>
     */
    private static function piiValues(string $letter): array
    {
        $json = file_get_contents(\dirname(__DIR__, 4).'/fixtures/letters/'.$letter.'.expected.json');
        self::assertIsString($json);
        /** @var array{pii: list<array{value: string}>} $expected */
        $expected = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        $values = [];
        foreach ($expected['pii'] as $entity) {
            foreach (explode("\n", $entity['value']) as $line) {
                $values[] = trim($line);
            }
        }

        return array_values(array_unique($values));
    }
}
