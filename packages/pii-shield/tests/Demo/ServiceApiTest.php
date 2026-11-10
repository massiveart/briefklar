<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Tests\Demo;

use Briefklar\PiiShield\Bridge\SymfonyAi\ModelDetector;
use Briefklar\PiiShield\Demo\ServiceApi;
use Briefklar\PiiShield\Learning\CacheCustomEntityStore;
use Briefklar\PiiShield\Learning\EntityLearner;
use Briefklar\PiiShield\Vault\EncryptedVault;
use Briefklar\PiiShield\Vault\Storage\Psr6CacheStorage;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\ModelCatalog\FallbackModelCatalog;
use Symfony\AI\Platform\ModelRouter\CatalogBasedModelRouter;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\Test\MockModelClient;
use Symfony\AI\Platform\Test\MockResultConverter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ServiceApiTest extends TestCase
{
    private const string TOKEN = 'test-token-0123456789';
    private const string LETTER = "Herrn\nJohann Beispielmann\n\nSVNR 4568 150392, Versicherung VN-2026-000417";

    private ServiceApi $api;

    protected function setUp(): void
    {
        $storage = new Psr6CacheStorage(new ArrayAdapter());
        $learner = new EntityLearner(new CacheCustomEntityStore(new ArrayAdapter()));
        $client = new MockModelClient(static fn (): string => '{"entities":[{"type":"PERSON","value":"Johann Beispielmann"}]}');
        $platform = new Platform([new Provider('mock', [$client], [new MockResultConverter()], new FallbackModelCatalog())], new CatalogBasedModelRouter());

        $this->api = new ServiceApi(
            new EncryptedVault($storage, EncryptedVault::generateKey()),
            $learner,
            static fn (): ModelDetector => new ModelDetector($platform, 'test-model', learnedTypes: null),
            self::TOKEN,
        );
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array{status: int, body: array<string, mixed>|null}
     */
    private function call(string $method, string $path, ?array $body = null, ?string $token = self::TOKEN, string $remote = '10.0.0.5'): array
    {
        return $this->api->handle($method, $path, $body, null !== $token ? 'Bearer '.$token : null, $remote);
    }

    public function testRedactReturnsPlaceholdersButNeverTheOriginals(): void
    {
        $response = $this->call('POST', '/v1/redact', ['text' => self::LETTER]);

        self::assertSame(200, $response['status']);
        $body = (array) $response['body'];
        self::assertSame("Herrn\n⟦PERSON_1⟧\n\nSVNR ⟦SVNR_1⟧, Versicherung VN-2026-000417", $body['text']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $body['vaultId']);
        self::assertSame([['placeholder' => '⟦PERSON_1⟧', 'type' => 'PERSON'], ['placeholder' => '⟦SVNR_1⟧', 'type' => 'SVNR']], $body['placeholders']);
        self::assertStringNotContainsString('Beispielmann', (string) json_encode($body));
    }

    public function testRestoreAndForget(): void
    {
        $vaultId = (string) ((array) $this->call('POST', '/v1/redact', ['text' => self::LETTER])['body'])['vaultId'];

        $restored = $this->call('POST', '/v1/restore', ['text' => 'Hallo ⟦PERSON_1⟧, ⟦IBAN_9⟧?', 'vaultId' => $vaultId]);
        self::assertSame(200, $restored['status']);
        self::assertSame(['text' => 'Hallo Johann Beispielmann, ⟦IBAN_9⟧?', 'unknownPlaceholders' => ['⟦IBAN_9⟧'], 'unusedPlaceholders' => ['⟦SVNR_1⟧']], $restored['body']);

        self::assertSame(204, $this->call('DELETE', '/v1/vault/'.$vaultId)['status']);
        self::assertSame(404, $this->call('POST', '/v1/restore', ['text' => '⟦PERSON_1⟧', 'vaultId' => $vaultId])['status']);
    }

    public function testMask(): void
    {
        $response = $this->call('POST', '/v1/mask', ['text' => 'IBAN AT61 1904 3002 3457 3201, SVNR 4568 150392']);

        self::assertSame(['text' => 'IBAN AT61 **** **** **** 3201, SVNR [SVNR]'], $response['body']);
    }

    public function testLearnedTypesAreUsedByRedact(): void
    {
        $learned = $this->call('POST', '/v1/learn', ['name' => 'INSURANCE_NUMBER', 'description' => 'Versichertennummer', 'examples' => ['VN-2026-000417', 'VN-2025-998812']]);

        self::assertSame(201, $learned['status']);
        self::assertSame('VN-\d{4}-\d{6}', ((array) ((array) $learned['body'])['type'])['pattern']);

        $redacted = (array) $this->call('POST', '/v1/redact', ['text' => self::LETTER])['body'];
        self::assertStringEndsWith('Versicherung ⟦INSURANCE_NUMBER_1⟧', (string) $redacted['text']);

        self::assertSame(['INSURANCE_NUMBER'], array_column((array) ((array) $this->call('GET', '/v1/entities')['body'])['types'], 'name'));
        self::assertSame(204, $this->call('DELETE', '/v1/entities/INSURANCE_NUMBER')['status']);
        self::assertSame([], ((array) $this->call('GET', '/v1/entities')['body'])['types']);
    }

    public function testLearnDryRunDoesNotSave(): void
    {
        $response = $this->call('POST', '/v1/learn', ['name' => 'INSURANCE_NUMBER', 'examples' => ['VN-2026-000417'], 'dryRun' => true]);

        self::assertSame(200, $response['status']);
        self::assertSame([], ((array) $this->call('GET', '/v1/entities')['body'])['types']);
    }

    public function testLearningErrorsAreClientErrors(): void
    {
        $response = $this->call('POST', '/v1/learn', ['name' => 'IBAN', 'examples' => ['VN-2026-000417']]);

        self::assertSame(422, $response['status']);
        self::assertArrayHasKey('error', (array) $response['body']);
    }

    public function testModelDetectionIsUsedWhenRequested(): void
    {
        $response = $this->call('POST', '/v1/redact', ['text' => 'Johann Beispielmann zahlt.', 'detection' => 'model']);

        self::assertSame('⟦PERSON_1⟧ zahlt.', ((array) $response['body'])['text']);
    }

    public function testModelErrorsDoNotLeakDetails(): void
    {
        $client = new MockModelClient(static fn (): string => 'not json, internal detail: http://10.0.0.7:11434');
        $platform = new Platform([new Provider('mock', [$client], [new MockResultConverter()], new FallbackModelCatalog())], new CatalogBasedModelRouter());
        $api = new ServiceApi(new EncryptedVault(new Psr6CacheStorage(new ArrayAdapter()), EncryptedVault::generateKey()), new EntityLearner(new CacheCustomEntityStore(new ArrayAdapter())), static fn (): ModelDetector => new ModelDetector($platform, 'secret-model-name'), self::TOKEN);

        $response = $api->handle('POST', '/v1/redact', ['text' => 'x', 'detection' => 'model'], 'Bearer '.self::TOKEN, '10.0.0.5');

        self::assertSame(['status' => 502, 'body' => ['error' => 'Model-based detection failed.']], $response);
    }

    public function testTokenIsRequired(): void
    {
        self::assertSame(401, $this->call('POST', '/v1/mask', ['text' => 'x'], token: null)['status']);
        self::assertSame(401, $this->call('POST', '/v1/mask', ['text' => 'x'], token: 'wrong')['status']);
    }

    public function testWithoutConfiguredTokenOnlyLocalhostIsAllowed(): void
    {
        $api = new ServiceApi(new EncryptedVault(new Psr6CacheStorage(new ArrayAdapter()), EncryptedVault::generateKey()), new EntityLearner(new CacheCustomEntityStore(new ArrayAdapter())), null, null);

        self::assertSame(200, $api->handle('POST', '/v1/mask', ['text' => 'x'], null, '127.0.0.1')['status']);
        self::assertSame(401, $api->handle('POST', '/v1/mask', ['text' => 'x'], null, '10.0.0.5')['status']);
    }

    public function testServiceModeRefusesToRunWithoutAToken(): void
    {
        $api = new ServiceApi(new EncryptedVault(new Psr6CacheStorage(new ArrayAdapter()), EncryptedVault::generateKey()), new EntityLearner(new CacheCustomEntityStore(new ArrayAdapter())), null, null, requireToken: true);

        self::assertSame(503, $api->handle('POST', '/v1/mask', ['text' => 'x'], null, '127.0.0.1')['status'], 'no loopback trust in service mode');
    }

    public function testHealthNeedsNoTokenAndRevealsNothing(): void
    {
        $response = $this->call('GET', '/v1/health', token: null);

        self::assertSame(['status' => 'ok', 'modelDetection' => true], $response['body']);
    }

    public function testUnknownRoutesAndMethods(): void
    {
        self::assertSame(404, $this->call('GET', '/v1/nope')['status']);
        self::assertSame(405, $this->call('GET', '/v1/redact')['status']);
        self::assertSame(400, $this->call('POST', '/v1/redact', ['text' => 42])['status']);
    }
}
