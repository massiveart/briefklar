<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Tests\Demo;

use Briefklar\PiiShield\Demo\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The server (demo page + /v1) end to end through the Symfony kernel, including its request guards.
 */
final class ServerTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function tearDown(): void
    {
        unset($_SERVER['PII_SERVICE_ONLY'], $_SERVER['PII_SERVICE_TOKEN'], $_SERVER['PII_LOCAL_PEERS'], $_SERVER['PII_DEMO_OLLAMA_URL']);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $server
     */
    private static function postJson(KernelBrowser $client, string $uri, array $body, array $server = []): void
    {
        $client->request('POST', $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_HOST' => '127.0.0.1:8088', ...$server], content: json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(KernelBrowser $client): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $data;
    }

    public function testDockerGatewayIsLocalOnlyWhenConfigured(): void
    {
        $gateway = ['REMOTE_ADDR' => '172.31.88.1'];
        $client = self::createClient();

        self::postJson($client, '/v1/redact', ['text' => 'SVNR 4568 150392'], $gateway);
        self::assertResponseStatusCodeSame(401);
        self::postJson($client, '/api/analyze', ['text' => 'x'], $gateway);
        self::assertResponseStatusCodeSame(403);

        $_SERVER['PII_LOCAL_PEERS'] = '172.31.88.1';
        self::postJson($client, '/v1/redact', ['text' => 'SVNR 4568 150392'], $gateway);
        self::assertResponseIsSuccessful();
        $client->request('GET', '/api/config', server: ['HTTP_HOST' => '127.0.0.1:8088', ...$gateway]);
        self::assertResponseIsSuccessful();

        self::postJson($client, '/v1/redact', ['text' => 'x'], ['REMOTE_ADDR' => '172.31.88.7']); // another container
        self::assertResponseStatusCodeSame(401);
    }

    public function testOllamaOnTheDockerHostIsLocal(): void
    {
        $_SERVER['PII_DEMO_OLLAMA_URL'] = 'http://host.docker.internal:11434';
        $client = self::createClient();
        $client->request('GET', '/api/config', server: ['HTTP_HOST' => '127.0.0.1:8088']);

        /** @var list<array{id: string, location: string}> $providers */
        $providers = self::json($client)['providers'];
        self::assertSame('local', array_column($providers, 'location', 'id')['ollama']);
    }

    public function testDemoPageIsServedWithSecurityHeaders(): void
    {
        $client = self::createClient();
        $client->request('GET', '/', server: ['HTTP_HOST' => '127.0.0.1:8088']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'pii-shield');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertStringContainsString("default-src 'self'", (string) $client->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function testDemoAnalysis(): void
    {
        $client = self::createClient();
        self::postJson($client, '/api/analyze', ['text' => 'SVNR 4568 150392', 'mode' => 'irreversible']);

        self::assertResponseIsSuccessful();
        self::assertSame('SVNR [SVNR]', self::json($client)['output']);
    }

    public function testDemoApiRejectsNonJsonAndForeignOrigins(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/analyze', server: ['CONTENT_TYPE' => 'text/plain', 'HTTP_HOST' => '127.0.0.1:8088'], content: '{"text":"x"}');
        self::assertResponseStatusCodeSame(403);

        self::postJson($client, '/api/analyze', ['text' => 'x'], ['HTTP_ORIGIN' => 'http://evil.example']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnknownHostsAreRejected(): void
    {
        $client = self::createClient();
        self::postJson($client, '/api/analyze', ['text' => 'x'], ['HTTP_HOST' => 'evil.example']);
        self::assertResponseStatusCodeSame(421);

        $client->request('GET', '/v1/health', server: ['HTTP_HOST' => 'evil.example']);
        self::assertResponseStatusCodeSame(421);
    }

    public function testEncodedPathsCannotBypassTheGuard(): void
    {
        $client = self::createClient();
        // "/%61pi/analyze" routes to the demo API; the guard must still apply (text/plain → 403).
        $client->request('POST', '/%61pi/analyze', server: ['CONTENT_TYPE' => 'text/plain', 'HTTP_HOST' => '127.0.0.1:8088'], content: '{"text":"x"}');

        self::assertNotSame(200, $client->getResponse()->getStatusCode());
    }

    public function testServiceApiFromLocalhostWithoutToken(): void
    {
        $client = self::createClient();
        self::postJson($client, '/v1/redact', ['text' => 'SVNR 4568 150392']);

        self::assertResponseIsSuccessful();
        self::assertSame('SVNR ⟦SVNR_1⟧', self::json($client)['text']);
        self::assertStringContainsString('"SVNR ⟦SVNR_1⟧"', (string) $client->getResponse()->getContent(), 'readable in a terminal: no \\u escapes');

        $client->request('GET', '/v1/openapi.yaml', server: ['HTTP_HOST' => '127.0.0.1:8088']);
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('openapi: 3.1.0', (string) $client->getResponse()->getContent());
    }

    public function testServiceModeServesOnlyV1AndNeedsAToken(): void
    {
        $_SERVER['PII_SERVICE_ONLY'] = '1';
        $client = self::createClient();

        $client->request('GET', '/', server: ['HTTP_HOST' => '127.0.0.1:8088']);
        self::assertResponseStatusCodeSame(404);

        self::postJson($client, '/v1/mask', ['text' => 'x']);
        self::assertResponseStatusCodeSame(503);
    }

    public function testServiceModeWithToken(): void
    {
        $_SERVER['PII_SERVICE_ONLY'] = '1';
        $_SERVER['PII_SERVICE_TOKEN'] = 'secret-token';
        $client = self::createClient();

        self::postJson($client, '/v1/mask', ['text' => 'SVNR 4568 150392'], ['HTTP_AUTHORIZATION' => 'Bearer secret-token']);

        self::assertResponseIsSuccessful();
        self::assertSame(['text' => 'SVNR [SVNR]'], self::json($client));
    }
}
