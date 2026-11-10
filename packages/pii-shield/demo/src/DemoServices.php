<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Demo;

use Briefklar\PiiShield\Bridge\SymfonyAi\ModelDetector;
use Briefklar\PiiShield\Bridge\SymfonyAi\ModelLocation;
use Briefklar\PiiShield\Vault\EncryptedVault;
use Briefklar\PiiShield\Vault\Storage\Psr6CacheStorage;
use Briefklar\PiiShield\Vault\Storage\VaultStorageInterface;
use Briefklar\PiiShield\Vault\VaultInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\AI\Platform\Bridge\Anthropic\Factory as AnthropicFactory;
use Symfony\AI\Platform\Bridge\Anthropic\ModelCatalog as AnthropicCatalog;
use Symfony\AI\Platform\Bridge\Generic\Factory as GenericFactory;
use Symfony\AI\Platform\Bridge\Mistral\Factory as MistralFactory;
use Symfony\AI\Platform\Bridge\Mistral\ModelCatalog as MistralCatalog;
use Symfony\AI\Platform\Bridge\Ollama\Factory as OllamaFactory;
use Symfony\AI\Platform\Bridge\OpenAi\Factory as OpenAiFactory;
use Symfony\AI\Platform\Bridge\OpenAi\ModelCatalog as OpenAiCatalog;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\PdoAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * Wires the demo: vault stores and detection models, configured from environment variables.
 * API keys stay on the server; the browser only learns whether a key is configured.
 *
 * Environment:
 *   PII_DEMO_OLLAMA_URL     default http://127.0.0.1:11434
 *   PII_DEMO_GENERIC_URL    OpenAI-compatible endpoint, e.g. vLLM or Ollama on your GPU server
 *   PII_DEMO_GENERIC_KEY    optional API key for it
 *   OPENAI_API_KEY, ANTHROPIC_API_KEY, MISTRAL_API_KEY
 *   PII_DEMO_REDIS_URL      e.g. redis://127.0.0.1:6379 (needs ext-redis or predis)
 */
final class DemoServices
{
    private const array PROVIDERS = [
        'ollama' => ['label' => 'Ollama', 'model' => 'qwen3:4b', 'key' => null],
        'generic' => ['label' => 'OpenAI-kompatibler Server (vLLM, Ollama, …)', 'model' => '', 'key' => 'PII_DEMO_GENERIC_KEY'],
        'mistral' => ['label' => 'Mistral (EU)', 'model' => 'mistral-small-latest', 'key' => 'MISTRAL_API_KEY'],
        'openai' => ['label' => 'OpenAI', 'model' => 'gpt-5', 'key' => 'OPENAI_API_KEY'],
        'anthropic' => ['label' => 'Anthropic', 'model' => 'claude-sonnet-5-5', 'key' => 'ANTHROPIC_API_KEY'],
    ];

    private const array STORES = [
        'filesystem' => 'Dateisystem',
        'sqlite' => 'SQLite-Datenbank',
        'redis' => 'Redis',
    ];

    public function __construct(
        private readonly string $varDir,
    ) {
    }

    /**
     * What the browser may know: available stores and providers, never keys.
     *
     * @return array{stores: list<array{id: string, label: string, available: bool}>, providers: list<array{id: string, label: string, location: string|null, model: string, endpoint: string|null, ready: bool}>}
     */
    public function config(): array
    {
        $stores = [];
        foreach (self::STORES as $id => $label) {
            $stores[] = ['id' => $id, 'label' => $label, 'available' => $this->storeAvailable($id)];
        }

        $providers = [];
        foreach (self::PROVIDERS as $id => $provider) {
            $endpoint = match ($id) {
                'ollama' => self::env('PII_DEMO_OLLAMA_URL') ?? 'http://127.0.0.1:11434',
                'generic' => self::env('PII_DEMO_GENERIC_URL'),
                default => null,
            };
            $providers[] = [
                'id' => $id,
                'label' => $provider['label'],
                'location' => match (true) {
                    null !== $endpoint => self::locationOf($endpoint)->value,
                    'generic' === $id => ModelLocation::OnPremise->value,
                    default => ModelLocation::External->value,
                },
                'model' => $provider['model'],
                'endpoint' => $endpoint,
                'ready' => match ($id) {
                    'ollama' => true,
                    'generic' => null !== $endpoint,
                    default => null !== self::env((string) $provider['key']),
                },
            ];
        }

        return ['stores' => $stores, 'providers' => $providers];
    }

    /**
     * Models to offer for a provider: installed ones for Ollama, the server's list for
     * OpenAI-compatible servers, the bridge's catalog for public providers.
     *
     * @return list<string>
     */
    public function models(string $provider, ?string $endpoint): array
    {
        $endpoint = null !== $endpoint && '' !== trim($endpoint) ? self::validEndpoint($endpoint) : null;
        $http = HttpClient::create(['timeout' => 5, 'max_redirects' => 0]);

        try {
            switch ($provider) {
                case 'ollama':
                    $url = $endpoint ?? self::env('PII_DEMO_OLLAMA_URL') ?? 'http://127.0.0.1:11434';
                    $data = $http->request('GET', $url.'/api/tags')->toArray();
                    $names = array_map(static fn (mixed $m): string => \is_array($m) && \is_string($m['name'] ?? null) ? $m['name'] : '', \is_array($data['models'] ?? null) ? $data['models'] : []);
                    break;
                case 'generic':
                    $configured = self::env('PII_DEMO_GENERIC_URL');
                    $url = $endpoint ?? $configured;
                    if (null === $url) {
                        return [];
                    }
                    $key = null !== $configured && rtrim($configured, '/') === $url ? self::env('PII_DEMO_GENERIC_KEY') : null;
                    $data = $http->request('GET', $url.'/v1/models', null !== $key ? ['auth_bearer' => $key] : [])->toArray();
                    $names = array_map(static fn (mixed $m): string => \is_array($m) && \is_string($m['id'] ?? null) ? $m['id'] : '', \is_array($data['data'] ?? null) ? $data['data'] : []);
                    break;
                case 'anthropic':
                case 'openai':
                case 'mistral':
                    $catalog = match ($provider) {
                        'anthropic' => new AnthropicCatalog(),
                        'openai' => new OpenAiCatalog(),
                        'mistral' => new MistralCatalog(),
                    };
                    $chat = array_filter($catalog->getModels(), static fn (array $m): bool => \in_array(Capability::INPUT_MESSAGES, $m['capabilities'], true));
                    // Text chat only (no speech, OCR, image or embedding models), newer versions first.
                    $names = array_values(array_filter(
                        array_map(strval(...), array_keys($chat)),
                        static fn (string $n): bool => 1 !== preg_match('/tts|ocr|embed|moderation|voxtral|realtime|audio|image|transcribe|search/i', $n),
                    ));
                    usort($names, static fn (string $a, string $b): int => strnatcmp($b, $a));
                    break;
                default:
                    throw new \InvalidArgumentException(\sprintf('Unknown provider "%s".', $provider));
            }
        } catch (ExceptionInterface) {
            return []; // server not reachable: the page falls back to free text input
        }

        return array_values(array_filter($names, static fn (string $n): bool => '' !== $n));
    }

    public static function validEndpoint(string $endpoint): string
    {
        $endpoint = rtrim(trim($endpoint), '/');
        if (!\in_array(parse_url($endpoint, \PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new \InvalidArgumentException('The server URL must start with http:// or https://.');
        }

        return $endpoint;
    }

    /**
     * @return \Closure(string): array{VaultInterface, VaultStorageInterface}
     */
    public function vaults(): \Closure
    {
        return function (string $store): array {
            $storage = new Psr6CacheStorage($this->pool($store));

            return [new EncryptedVault($storage, $this->key()), $storage];
        };
    }

    /**
     * @return \Closure(array<string, mixed>): ModelDetector
     */
    public function modelDetectors(): \Closure
    {
        return function (array $settings): ModelDetector {
            $provider = \is_string($settings['provider'] ?? null) ? $settings['provider'] : '';
            $model = \is_string($settings['model'] ?? null) && '' !== trim($settings['model']) ? trim($settings['model']) : (self::PROVIDERS[$provider]['model'] ?? '');
            $endpoint = \is_string($settings['endpoint'] ?? null) && '' !== trim($settings['endpoint']) ? self::validEndpoint($settings['endpoint']) : null;

            [$platform, $location, $options] = $this->platform($provider, $endpoint);

            return new ModelDetector($platform, $model, $location, true === ($settings['acknowledgeExternal'] ?? false), $options);
        };
    }

    /**
     * @return array{PlatformInterface, ModelLocation, array<string, mixed>}
     */
    private function platform(string $provider, ?string $endpoint): array
    {
        $key = static fn (string $name): string => self::env($name) ?? throw new \InvalidArgumentException(\sprintf('Set %s in the environment of the demo server to use this provider.', $name));
        // Self-hosted models answer only when done; allow for large models on modest hardware.
        // No redirects: the URL may be typed in the browser and must not bounce elsewhere.
        $selfHosted = HttpClient::create(['timeout' => 170, 'max_redirects' => 0]);
        $deterministic = ['temperature' => 0];

        switch ($provider) {
            case 'ollama':
                $endpoint ??= self::env('PII_DEMO_OLLAMA_URL') ?? 'http://127.0.0.1:11434';

                // No chain-of-thought (faster). No JSON mode: not every model supports it; the prompt asks for JSON.
                return [OllamaFactory::createPlatform($endpoint, httpClient: $selfHosted), self::locationOf($endpoint), [...$deterministic, 'think' => false]];
            case 'generic':
                $configured = self::env('PII_DEMO_GENERIC_URL');
                $endpoint ??= $configured ?? throw new \InvalidArgumentException('Enter the URL of the OpenAI-compatible server.');
                // The server's key only ever goes to the server it was configured for.
                $apiKey = null !== $configured && rtrim($configured, '/') === $endpoint ? self::env('PII_DEMO_GENERIC_KEY') : null;

                return [GenericFactory::createPlatform($endpoint, $apiKey, $selfHosted, supportsEmbeddings: false), self::locationOf($endpoint), $deterministic];
            case 'mistral':
                return [MistralFactory::createPlatform($key('MISTRAL_API_KEY')), ModelLocation::External, $deterministic];
            case 'openai':
                return [OpenAiFactory::createPlatform($key('OPENAI_API_KEY')), ModelLocation::External, []];
            case 'anthropic':
                // Current Claude models reject sampling parameters.
                return [AnthropicFactory::createPlatform($key('ANTHROPIC_API_KEY')), ModelLocation::External, []];
            default:
                throw new \InvalidArgumentException(\sprintf('Unknown provider "%s".', $provider));
        }
    }

    private static function locationOf(string $endpoint): ModelLocation
    {
        $host = parse_url($endpoint, \PHP_URL_HOST);

        return \in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true) ? ModelLocation::Local : ModelLocation::OnPremise;
    }

    private function pool(string $store): CacheItemPoolInterface
    {
        if (!$this->storeAvailable($store)) {
            throw new \InvalidArgumentException(\sprintf('The store "%s" is not available on this server.', $store));
        }

        return match ($store) {
            'filesystem' => new FilesystemAdapter('vault', 0, $this->varDir.'/cache'),
            'sqlite' => $this->sqlitePool(),
            'redis' => new RedisAdapter(RedisAdapter::createConnection((string) self::env('PII_DEMO_REDIS_URL')), 'pii-shield-vault'),
            default => throw new \InvalidArgumentException(\sprintf('Unknown store "%s".', $store)),
        };
    }

    private function sqlitePool(): PdoAdapter
    {
        $file = $this->varDir.'/vault.sqlite';
        $isNew = !is_file($file);
        $pool = new PdoAdapter('sqlite:'.$file, 'pii-shield-vault');
        if ($isNew) {
            $pool->createTable();
        }

        return $pool;
    }

    private function storeAvailable(string $store): bool
    {
        return match ($store) {
            'filesystem' => true,
            'sqlite' => \extension_loaded('pdo_sqlite'),
            'redis' => null !== self::env('PII_DEMO_REDIS_URL') && (\extension_loaded('redis') || class_exists(\Predis\Client::class)), // Predis: pure PHP fallback
            default => false,
        };
    }

    private function key(): string
    {
        if (!is_dir($this->varDir)) {
            mkdir($this->varDir, 0700, true);
        }
        $file = $this->varDir.'/vault.key';
        if (!is_file($file)) {
            file_put_contents($file, base64_encode(EncryptedVault::generateKey()));
            chmod($file, 0600);
        }

        return (string) base64_decode((string) file_get_contents($file), true);
    }

    private static function env(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return \is_string($value) && '' !== $value ? $value : null;
    }
}
