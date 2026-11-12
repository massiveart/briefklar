<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The MCP endpoint over HTTP: reachable for MCP clients, closed to other websites (McpRequestGuard).
 */
final class McpHttpTest extends WebTestCase
{
    private const string INITIALIZE = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"test","version":"0"}}}';

    public function testAnswersAnMcpClient(): void
    {
        $client = self::createClient();
        $client->request('POST', 'http://localhost/mcp', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'], content: self::INITIALIZE);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('"name":"briefklar"', $client->getInternalResponse()->getContent());
    }

    public function testAcceptsTheMcpInspector(): void
    {
        $client = self::createClient();
        $client->request('POST', 'http://localhost/mcp', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'HTTP_ORIGIN' => 'http://localhost:6274'], content: self::INITIALIZE);

        self::assertResponseIsSuccessful();
    }

    public function testRefusesOtherWebsites(): void
    {
        $client = self::createClient();
        $client->request('POST', 'http://localhost/mcp', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://evil.example'], content: self::INITIALIZE);

        self::assertResponseStatusCodeSame(403);
    }

    public function testRefusesFormPosts(): void
    {
        $client = self::createClient();
        // What a cross-site form can send without a CORS preflight.
        $client->request('POST', 'http://localhost/mcp', server: ['CONTENT_TYPE' => 'text/plain'], content: self::INITIALIZE);

        self::assertResponseStatusCodeSame(415);
    }

    public function testRefusesUnknownHosts(): void
    {
        $client = self::createClient();
        $client->request('POST', 'http://attacker.example/mcp', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'], content: self::INITIALIZE);

        self::assertResponseStatusCodeSame(403);
    }
}
