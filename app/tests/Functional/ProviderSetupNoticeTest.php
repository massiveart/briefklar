<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A fresh clone without a provider key says what to configure (README quickstart),
 * instead of failing every upload with "try again in a minute".
 */
final class ProviderSetupNoticeTest extends WebTestCase
{
    /** Set explicitly: compose.yaml passes app/.env.local (e.g. AI_PLATFORM=ollama) as real env vars. */
    private const array VARIABLES = ['AI_PLATFORM', 'HUGGINGFACE_API_KEY'];

    /** @var array<string, array{mixed, mixed}> */
    private array $previous = [];

    protected function setUp(): void
    {
        foreach (self::VARIABLES as $name) {
            $this->previous[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null];
        }
    }

    protected function tearDown(): void
    {
        // Restore, not unset: .env is loaded once per test run, later kernels need the variables.
        foreach ($this->previous as $name => [$env, $server]) {
            $_ENV[$name] = $env;
            $_SERVER[$name] = $server;
        }
        parent::tearDown();
    }

    public function testNoNoticeWhenTheProviderIsSetUp(): void
    {
        $this->useHuggingFace('hf-test-key');
        $client = self::createClient();
        $client->request('GET', '/de');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.setup-note');
    }

    public function testNamesTheMissingKey(): void
    {
        $this->useHuggingFace('');
        $client = self::createClient();
        $client->request('GET', '/en');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.setup-note', 'HUGGINGFACE_API_KEY');
        self::assertSelectorTextContains('.setup-note', 'app/.env.local');
    }

    private function useHuggingFace(string $key): void
    {
        $_ENV['AI_PLATFORM'] = $_SERVER['AI_PLATFORM'] = 'huggingface';
        $_ENV['HUGGINGFACE_API_KEY'] = $_SERVER['HUGGINGFACE_API_KEY'] = $key;
    }
}
