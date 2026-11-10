<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Tests\Demo;

use Briefklar\PiiShield\Demo\ServiceApi;
use Briefklar\PiiShield\Learning\CacheCustomEntityStore;
use Briefklar\PiiShield\Learning\EntityLearner;
use Briefklar\PiiShield\Vault\EncryptedVault;
use Briefklar\PiiShield\Vault\Storage\Psr6CacheStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Yaml\Yaml;

/**
 * Keeps docs/openapi.yaml and ServiceApi in sync: every documented operation is callable with its
 * example and answers with a documented status; every route of the service is documented.
 */
final class OpenApiContractTest extends TestCase
{
    private const string SPEC = __DIR__.'/../../docs/openapi.yaml';

    /**
     * @return array<string, mixed>
     */
    private static function spec(): array
    {
        /** @var array<string, mixed> $spec */
        $spec = Yaml::parseFile(self::SPEC);

        return $spec;
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>|null, list<int>}>
     */
    public static function operationProvider(): iterable
    {
        /** @var array<string, array<string, array<string, mixed>>> $paths */
        $paths = self::spec()['paths'];
        foreach ($paths as $path => $operations) {
            if ('/v1/openapi.yaml' === $path) {
                continue; // served as a static file by the front controller
            }
            foreach ($operations as $method => $operation) {
                /** @var array<string, mixed>|null $example */
                $example = $operation['requestBody']['content']['application/json']['example'] ?? null;
                $statuses = array_map(intval(...), array_keys((array) $operation['responses']));
                $concrete = strtr($path, ['{vaultId}' => str_repeat('a', 32), '{name}' => 'INSURANCE_NUMBER']);

                yield strtoupper($method).' '.$path => [strtoupper($method), $concrete, $example, $statuses];
            }
        }
    }

    /**
     * @param array<string, mixed>|null $example
     * @param list<int>                 $statuses
     */
    #[DataProvider('operationProvider')]
    public function testDocumentedOperationAnswersWithADocumentedStatus(string $method, string $path, ?array $example, array $statuses): void
    {
        $learner = new EntityLearner(new CacheCustomEntityStore(new ArrayAdapter()));
        $learner->learn('INSURANCE_NUMBER', 'Polizzennummer', ['VN-2026-000417', 'VN-2025-998812']);
        $api = new ServiceApi(new EncryptedVault(new Psr6CacheStorage(new ArrayAdapter()), EncryptedVault::generateKey()), $learner, null, 'token');

        $response = $api->handle($method, $path, $example, 'Bearer token', '10.0.0.1');

        self::assertContains($response['status'], $statuses, \sprintf('%s %s answered %d: %s', $method, $path, $response['status'], json_encode($response['body'])));
    }

    public function testEveryServiceRouteIsDocumented(): void
    {
        /** @var array<string, array<string, mixed>> $paths */
        $paths = self::spec()['paths'];
        $documented = [];
        foreach ($paths as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $documented[] = strtoupper($method).' '.$path;
            }
        }

        $routes = ['POST /v1/redact', 'POST /v1/restore', 'DELETE /v1/vault/{vaultId}', 'POST /v1/mask', 'POST /v1/learn', 'GET /v1/entities', 'DELETE /v1/entities/{name}', 'GET /v1/health', 'GET /v1/openapi.yaml'];
        sort($routes);
        sort($documented);

        self::assertSame($routes, $documented);
    }

    public function testRedactExampleMatchesTheDocumentedResponse(): void
    {
        /** @var array<string, mixed> $operation */
        $operation = self::spec()['paths']['/v1/redact']['post'];
        $learner = new EntityLearner(new CacheCustomEntityStore(new ArrayAdapter()));
        $learner->learn('INSURANCE_NUMBER', 'Polizzennummer', ['VN-2026-000417', 'VN-2025-998812']);
        $api = new ServiceApi(new EncryptedVault(new Psr6CacheStorage(new ArrayAdapter()), EncryptedVault::generateKey()), $learner, null, 'token');

        /** @var array<string, mixed> $request */
        $request = $operation['requestBody']['content']['application/json']['example'];
        /** @var array<string, mixed> $documented */
        $documented = $operation['responses']['200']['content']['application/json']['example'];
        $body = (array) $api->handle('POST', '/v1/redact', $request, 'Bearer token', '10.0.0.1')['body'];

        self::assertSame($documented['text'], $body['text']);
        self::assertSame($documented['placeholders'], $body['placeholders']);
    }
}
