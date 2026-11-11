<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Demo;

use Briefklar\PiiShield\Bridge\SymfonyAi\ModelDetector;
use Briefklar\PiiShield\Bridge\SymfonyAi\ModelLocation;
use Briefklar\PiiShield\Learning\CacheCustomEntityStore;
use Briefklar\PiiShield\Learning\CustomEntityStoreInterface;
use Briefklar\PiiShield\Learning\EntityLearner;
use Briefklar\PiiShield\Learning\JsonFileCustomEntityStore;
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
use Symfony\Component\HttpFoundation\IpUtils;
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
 *   PII_LOCAL_PEERS         addresses that count as this machine besides loopback; "gateway" = the Docker gateway
 */
final class DemoServices
{
    private const array PROVIDERS = [
        'ollama' => ['label' => 'Ollama', 'model' => 'qwen3:4b-instruct', 'key' => null],
        'generic' => ['label' => 'OpenAI-kompatibler Server (vLLM, Ollama, …)', 'model' => '', 'key' => 'PII_DEMO_GENERIC_KEY'],
        'mistral' => ['label' => 'Mistral (EU)', 'model' => 'mistral-small-latest', 'key' => 'MISTRAL_API_KEY'],
        'openai' => ['label' => 'OpenAI', 'model' => 'gpt-5', 'key' => 'OPENAI_API_KEY'],
        'anthropic' => ['label' => 'Anthropic', 'model' => 'claude-sonnet-5-5', 'key' => 'ANTHROPIC_API_KEY'],
    ];

    private const int LOG_RETENTION_DAYS = 7;
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
                    $configured = self::openAiBase(self::env('PII_DEMO_GENERIC_URL'));
                    $url = self::openAiBase($endpoint) ?? $configured;
                    if (null === $url) {
                        return [];
                    }
                    $key = $configured === $url ? self::env('PII_DEMO_GENERIC_KEY') : null;
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

    /**
     * Appends one line to demo/var/log/demo-YYYY-MM-DD.log. Metadata only; the model's raw answer is added
     * by the caller only with PII_DEMO_DEBUG=1, because it can contain personal data. Therefore the files
     * are readable by the owner only and deleted after LOG_RETENTION_DAYS.
     *
     * @param array<string, mixed> $event
     */
    public function log(array $event): void
    {
        $dir = $this->varDir.'/log';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $files = glob($dir.'/demo*.log');
        foreach (false === $files ? [] : $files as $file) {
            if (filemtime($file) < time() - self::LOG_RETENTION_DAYS * 86_400) {
                @unlink($file);
            }
        }

        $file = $dir.'/demo-'.date('Y-m-d').'.log';
        if (!is_file($file)) {
            touch($file);
            chmod($file, 0600);
        }
        file_put_contents($file, json_encode(['time' => date('c'), ...$event], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)."\n", \FILE_APPEND | \LOCK_EX);
    }

    public static function debug(): bool
    {
        return '1' === self::env('PII_DEMO_DEBUG');
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
     * The /v1 HTTP API, configured from the environment (see demo/.env.dist).
     */
    public function serviceApi(): ServiceApi
    {
        $model = null;
        $provider = self::env('PII_SERVICE_MODEL_PROVIDER');
        if (null !== $provider) {
            $settings = [
                'provider' => $provider,
                'model' => self::env('PII_SERVICE_MODEL') ?? '',
                'endpoint' => self::env('PII_SERVICE_MODEL_URL'),
                'acknowledgeExternal' => 'true' === self::env('PII_SERVICE_ACKNOWLEDGE_EXTERNAL'),
            ];
            $model = fn (): ModelDetector => ($this->modelDetectors())($settings);
        }
        [$vault] = ($this->vaults())(self::env('PII_SERVICE_VAULT_STORE') ?? 'filesystem');

        return new ServiceApi($vault, $this->learner(), $model, self::env('PII_SERVICE_TOKEN'), DemoApi::VAULT_TTL, self::serviceOnly());
    }

    public function demoApi(): DemoApi
    {
        return new DemoApi($this->vaults(), $this->modelDetectors(), $this->learner());
    }

    public function serviceToken(): ?string
    {
        return self::env('PII_SERVICE_TOKEN');
    }

    /**
     * Whether a request comes from this machine: loopback, or an address listed in PII_LOCAL_PEERS
     * (comma-separated IPs or CIDRs; "gateway" = this container's default gateway). In Docker, a port
     * published on the host's 127.0.0.1 arrives from the gateway (compose.yaml).
     */
    public static function isLocalPeer(?string $address): bool
    {
        $peers = [];
        foreach (explode(',', self::env('PII_LOCAL_PEERS') ?? '') as $peer) {
            $peer = trim($peer);
            $peers[] = 'gateway' === $peer ? self::defaultGateway((string) @file_get_contents('/proc/net/route')) : $peer;
        }
        $peers = array_filter($peers, static fn (?string $p): bool => null !== $p && '' !== $p);

        return null !== $address && IpUtils::checkIp($address, ['127.0.0.1', '::1', ...$peers]);
    }

    /**
     * The default gateway from a Linux route table (/proc/net/route: addresses as little-endian hex).
     */
    public static function defaultGateway(string $routeTable): ?string
    {
        foreach (\array_slice(explode("\n", $routeTable), 1) as $line) {
            $columns = preg_split('/\s+/', trim($line));
            if (\is_array($columns) && isset($columns[2]) && '00000000' === $columns[1] && 8 === \strlen($columns[2]) && ctype_xdigit($columns[2])) {
                $address = unpack('V', (string) hex2bin($columns[2]));

                return false === $address ? null : long2ip((int) $address[1]);
            }
        }

        return null;
    }

    /**
     * Extra host names the APIs answer to (PII_SERVICE_ALLOWED_HOSTS, comma-separated).
     *
     * @return list<string>
     */
    public static function allowedHosts(): array
    {
        return array_values(array_filter(array_map(trim(...), explode(',', self::env('PII_SERVICE_ALLOWED_HOSTS') ?? '')), static fn (string $h): bool => '' !== $h));
    }

    /**
     * Learned entity types: JSON file in demo/var by default, Redis with PII_SERVICE_LEARNED_STORE=redis.
     */
    public function learner(): EntityLearner
    {
        return new EntityLearner($this->learnedStore());
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
            if ('' === $model) {
                throw new \InvalidArgumentException('Choose a model first.');
            }
            $endpoint = \is_string($settings['endpoint'] ?? null) && '' !== trim($settings['endpoint']) ? self::validEndpoint($settings['endpoint']) : null;

            [$platform, $location, $options] = $this->platform($provider, $endpoint, false !== ($settings['fast'] ?? true));

            return new ModelDetector($platform, $model, $location, true === ($settings['acknowledgeExternal'] ?? false), $options, $this->learnedStore());
        };
    }

    /**
     * @return array{PlatformInterface, ModelLocation, array<string, mixed>}
     */
    private function platform(string $provider, ?string $endpoint, bool $fast): array
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
                return [OllamaFactory::createPlatform($endpoint, httpClient: $selfHosted), self::locationOf($endpoint), $fast ? [...$deterministic, 'think' => false] : $deterministic];
            case 'generic':
                $configured = self::openAiBase(self::env('PII_DEMO_GENERIC_URL'));
                $endpoint = self::openAiBase($endpoint) ?? $configured ?? throw new \InvalidArgumentException('Enter the URL of the OpenAI-compatible server.');
                // The server's key only ever goes to the server it was configured for.
                $apiKey = $configured === $endpoint ? self::env('PII_DEMO_GENERIC_KEY') : null;

                // Fast: no reasoning tokens (Ollama's /v1 understands "none"; turn off for servers that do not).
                return [GenericFactory::createPlatform($endpoint, $apiKey, $selfHosted, supportsEmbeddings: false), self::locationOf($endpoint), $fast ? [...$deterministic, 'reasoning_effort' => 'none'] : $deterministic];
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

    private function learnedStore(): CustomEntityStoreInterface
    {
        return 'redis' === self::env('PII_SERVICE_LEARNED_STORE')
            ? new CacheCustomEntityStore($this->pool('redis'))
            : new JsonFileCustomEntityStore($this->varDir.'/learned-types.json');
    }

    /**
     * Service mode (Docker image): only /v1, always with a token, no demo page.
     */
    public static function serviceOnly(): bool
    {
        return '1' === self::env('PII_SERVICE_ONLY');
    }

    /**
     * Base URL without a trailing "/v1": many guides give the base URL with it, the bridge adds it itself.
     */
    private static function openAiBase(?string $url): ?string
    {
        return null === $url ? null : (string) preg_replace('~/v1/?$~', '', rtrim($url, '/'));
    }

    private static function locationOf(string $endpoint): ModelLocation
    {
        $host = parse_url($endpoint, \PHP_URL_HOST);

        // host.docker.internal: the machine running the containers, e.g. Ollama on the Mac.
        return \in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]', 'host.docker.internal'], true) ? ModelLocation::Local : ModelLocation::OnPremise;
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
