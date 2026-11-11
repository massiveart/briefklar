<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Tests\Demo;

use Briefklar\PiiShield\Demo\Kernel;
use Briefklar\PiiShield\Tests\Dig;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The MCP server over Streamable HTTP (/mcp), as an MCP client would use it.
 */
final class McpTest extends WebTestCase
{
    private ?string $sessionId = null;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function tearDown(): void
    {
        unset($_SERVER['PII_SERVICE_TOKEN']);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed>  $params
     * @param array<string, string> $server
     *
     * @return array<string, mixed>
     */
    private function rpc(KernelBrowser $client, string $method, array $params = [], array $server = []): array
    {
        $client->request('POST', '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_HOST' => '127.0.0.1:8088',
            ...(null !== $this->sessionId ? ['HTTP_MCP_SESSION_ID' => $this->sessionId] : []),
            ...$server,
        ], content: json_encode(['jsonrpc' => '2.0', 'id' => random_int(1, 1_000_000), 'method' => $method, 'params' => (object) $params], \JSON_THROW_ON_ERROR));

        $response = $client->getResponse();
        $this->sessionId ??= $response->headers->get('Mcp-Session-Id');
        $content = (string) $response->getContent();
        // Streamable HTTP may answer as SSE: take the JSON of the data line.
        if (str_starts_with((string) $response->headers->get('Content-Type'), 'text/event-stream')) {
            preg_match('/^data: (.+)$/m', $content, $m);
            $content = $m[1] ?? '{}';
        }

        /** @var array<string, mixed> $data */
        $data = '' === $content ? [] : json_decode($content, true, flags: \JSON_THROW_ON_ERROR);

        return $data;
    }

    private function initialize(KernelBrowser $client): void
    {
        $this->rpc($client, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1.0']]);
        self::assertNotNull($this->sessionId, 'initialize returns a session id');
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function callTool(KernelBrowser $client, string $name, array $arguments): array
    {
        $response = $this->rpc($client, 'tools/call', ['name' => $name, 'arguments' => $arguments]);
        self::assertArrayHasKey('result', $response, (string) json_encode($response));
        /** @var array{content: list<array{text: string}>, isError?: bool} $result */
        $result = $response['result'];
        self::assertFalse($result['isError'] ?? false, $result['content'][0]['text'] ?? '');

        /** @var array<string, mixed> $data */
        $data = json_decode($result['content'][0]['text'], true, flags: \JSON_THROW_ON_ERROR);

        return $data;
    }

    public function testListsThePiiTools(): void
    {
        $client = self::createClient();
        $this->initialize($client);

        $tools = $this->rpc($client, 'tools/list');

        /** @var list<array{name: string}> $list */
        $list = Dig::get($tools, 'result', 'tools');
        self::assertEqualsCanonicalizing(
            ['redact_text', 'restore_text', 'mask_text', 'learn_entity_type', 'list_entity_types'],
            array_column($list, 'name'),
        );
    }

    public function testRedactThenRestoreWithoutSendingOriginals(): void
    {
        $client = self::createClient();
        $this->initialize($client);

        $redacted = $this->callTool($client, 'redact_text', ['text' => "Herrn\nJohann Beispielmann\nSVNR 4568 150392"]);

        self::assertSame("Herrn\n⟦PERSON_1⟧\nSVNR ⟦SVNR_1⟧", $redacted['text']);
        self::assertStringNotContainsString('Beispielmann', (string) json_encode($redacted));

        $restored = $this->callTool($client, 'restore_text', ['text' => '⟦PERSON_1⟧ / ⟦SVNR_1⟧', 'vaultId' => $redacted['vaultId']]);
        self::assertSame('Johann Beispielmann / 4568 150392', $restored['text']);
    }

    public function testMaskAndLearn(): void
    {
        $client = self::createClient();
        $this->initialize($client);

        self::assertSame('SVNR [SVNR]', $this->callTool($client, 'mask_text', ['text' => 'SVNR 4568 150392'])['text']);

        $learned = $this->callTool($client, 'learn_entity_type', ['name' => 'MCP_TEST_NUMBER', 'examples' => ['MT-2026-0001', 'MT-2025-0002'], 'dryRun' => true]);
        self::assertSame('MT-\d{4}-\d{4}', Dig::get($learned, 'type', 'pattern'));
    }

    public function testToolErrorsAreToolResultsNotCrashes(): void
    {
        $client = self::createClient();
        $this->initialize($client);

        $response = $this->rpc($client, 'tools/call', ['name' => 'restore_text', 'arguments' => ['text' => 'x', 'vaultId' => str_repeat('0', 32)]]);

        self::assertTrue(Dig::get($response, 'result', 'isError'));
    }

    public function testForeignOriginsAndNonJsonAreRejected(): void
    {
        $client = self::createClient();

        $client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_HOST' => '127.0.0.1:8088', 'HTTP_ORIGIN' => 'https://evil.example'], content: '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}');
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'text/plain', 'HTTP_HOST' => '127.0.0.1:8088'], content: '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}');
        self::assertResponseStatusCodeSame(403);
    }

    public function testLocalBrowserToolsLikeTheInspectorMayConnect(): void
    {
        $client = self::createClient();
        $this->initialize($client);

        $tools = $this->rpc($client, 'tools/list', server: ['HTTP_ORIGIN' => 'http://localhost:6274']);

        self::assertArrayHasKey('result', $tools);
        self::assertNotSame('*', $client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }

    public function testTokenIsRequiredWhenConfigured(): void
    {
        $_SERVER['PII_SERVICE_TOKEN'] = 'secret-token';
        $client = self::createClient();

        $client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_HOST' => '127.0.0.1:8088'], content: '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}');

        self::assertResponseStatusCodeSame(401);
    }
}
