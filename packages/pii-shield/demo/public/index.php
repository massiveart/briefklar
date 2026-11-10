<?php

declare(strict_types=1);

/*
 * Front controller for the local demo: `composer demo` or `make demo`, then open http://127.0.0.1:8088.
 * Runs on PHP's built-in server only. Nothing is sent to any external service.
 */

use Briefklar\PiiShield\Demo\DemoApi;
use Briefklar\PiiShield\Demo\DemoServices;
use Briefklar\PiiShield\Exception\DetectionFailedException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

// Provider keys and URLs: demo/.env.local (git-ignored), see demo/.env.dist. Real environment variables win.
if (is_file(dirname(__DIR__).'/.env.local')) {
    (new Symfony\Component\Dotenv\Dotenv())->load(dirname(__DIR__).'/.env.local');
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH);
$path = is_string($path) ? $path : '/';

// Service mode (Docker image): only /v1 exists; the demo page, its assets and /api are not served.
if (DemoServices::serviceOnly() && !str_starts_with($path, '/v1/')) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo '{"error":"Not found."}';

    return true;
}

if ('cli-server' === \PHP_SAPI && '/' !== $path && is_file(__DIR__.$path)) {
    return false; // static assets
}

header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

if ('/' === $path) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__.'/index.html');

    return true;
}

$json = static function (mixed $data, int $status = 200): bool {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

    return true;
};

$services = new DemoServices(dirname(__DIR__).'/var');

// DNS rebinding: a foreign domain resolving to 127.0.0.1 must not reach the APIs. Only known hosts are served.
$allowedHosts = array_filter(array_map('trim', explode(',', (string) ($_SERVER['PII_SERVICE_ALLOWED_HOSTS'] ?? $_ENV['PII_SERVICE_ALLOWED_HOSTS'] ?? ''))));
$host = (string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
if ((str_starts_with($path, '/api/') || str_starts_with($path, '/v1/')) && !in_array($host, ['127.0.0.1', 'localhost', '[::1]', ...$allowedHosts], true)) {
    return $json(['error' => 'Unknown host.'], 421);
}

if ('/v1/openapi.yaml' === $path) {
    header('Content-Type: application/yaml; charset=utf-8');
    readfile(dirname(__DIR__, 2).'/docs/openapi.yaml');

    return true;
}

// Versioned HTTP API for apps (see docs/openapi.yaml). Token auth; JSON only, so browsers cannot send it cross-site.
if (str_starts_with($path, '/v1/')) {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    if ((null !== $origin && $origin !== 'http://'.($_SERVER['HTTP_HOST'] ?? ''))
        || ('POST' === $method && !str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json'))) {
        return $json(['error' => 'Send JSON (Content-Type: application/json) from a non-browser client or this page.'], 403);
    }
    set_time_limit(180);
    try {
        $body = 'POST' === $method ? json_decode((string) file_get_contents('php://input'), true, 16, \JSON_THROW_ON_ERROR) : null;
    } catch (JsonException) {
        return $json(['error' => 'Invalid JSON.'], 400);
    }
    if (null !== $body && !is_array($body)) {
        return $json(['error' => 'Expected a JSON object.'], 400);
    }
    /** @var array<string, mixed>|null $body */
    $response = $services->serviceApi()->handle($method, $path, $body, $_SERVER['HTTP_AUTHORIZATION'] ?? null, (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if (204 === $response['status']) {
        http_response_code(204);

        return true;
    }

    return $json($response['body'], $response['status']);
}

if ('/api/config' === $path) {
    return $json($services->config());
}

if ('/api/samples' === $path) {
    $samples = [];
    foreach (glob(dirname(__DIR__, 4).'/fixtures/letters/*.txt') ?: [] as $file) {
        $samples[] = ['name' => basename($file, '.txt'), 'text' => (string) file_get_contents($file)];
    }

    return $json($samples);
}

if (!in_array($path, ['/api/analyze', '/api/restore', '/api/models', '/api/learn', '/api/entities', '/api/entities/forget'], true)) {
    return $json(['error' => 'Not found.'], 404);
}
if ('POST' !== ($_SERVER['REQUEST_METHOD'] ?? 'GET')) {
    return $json(['error' => 'Use POST.'], 405);
}
// The demo page is for the local user only: check the real peer address, not just the Host header.
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    return $json(['error' => 'The demo API is available on this machine only.'], 403);
}
// CSRF: other websites in the same browser must not drive this API. JSON requests from a foreign
// origin need a CORS preflight, which this server never grants; a foreign Origin is rejected outright.
$origin = $_SERVER['HTTP_ORIGIN'] ?? null;
$ownOrigin = 'http://'.($_SERVER['HTTP_HOST'] ?? '');
if (!str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') || (null !== $origin && $origin !== $ownOrigin)) {
    return $json(['error' => 'Only JSON requests from this page are accepted.'], 403);
}

// Local models can take a while; keep the request alive.
set_time_limit(180);
$api = new DemoApi($services->vaults(), $services->modelDetectors(), $services->learner());

// Request log (demo/var/log/demo.log): what was asked, how long it took, what failed. Never the text.
$started = hrtime(true);
$model = null;
$logEvent = static function (int $status, ?string $error = null, ?string $modelAnswer = null) use ($services, $path, &$model, $started): void {
    $services->log(array_filter([
        'path' => $path,
        'status' => $status,
        'ms' => round((hrtime(true) - $started) / 1e6, 1),
        'model' => $model,
        'error' => $error,
        'modelAnswer' => DemoServices::debug() ? $modelAnswer : null,
    ], static fn (mixed $v): bool => null !== $v));
};

try {
    $request = json_decode((string) file_get_contents('php://input'), true, 16, \JSON_THROW_ON_ERROR);
    if (!is_array($request)) {
        throw new InvalidArgumentException('Expected a JSON object.');
    }
    /** @var array<string, mixed> $request */
    // With a service token configured, learned types are changed through /v1 only (same store, same rules).
    // Only a dry run of /api/learn writes nothing and stays available; forget never does.
    $serviceToken = $_SERVER['PII_SERVICE_TOKEN'] ?? $_ENV['PII_SERVICE_TOKEN'] ?? '';
    if ('' !== $serviceToken && ('/api/entities/forget' === $path || ('/api/learn' === $path && true !== ($request['dryRun'] ?? false)))) {
        return $json(['error' => 'Learned types are managed through the /v1 API with a token on this server.'], 403);
    }
    if (is_array($request['model'] ?? null) && 'regex' !== ($request['detection'] ?? 'regex')) {
        $model = sprintf('%s/%s', is_string($request['model']['provider'] ?? null) ? $request['model']['provider'] : '?', is_string($request['model']['model'] ?? null) ? $request['model']['model'] : '?');
    }
    $response = match ($path) {
        '/api/analyze' => $api->analyze($request),
        '/api/restore' => $api->restore($request),
        '/api/learn' => $api->learn($request),
        '/api/entities' => $api->entities(),
        '/api/entities/forget' => $api->forget($request),
        default => ['models' => $services->models(is_string($request['provider'] ?? null) ? $request['provider'] : '', is_string($request['endpoint'] ?? null) ? $request['endpoint'] : null)],
    };

    $logEvent(200);

    return $json($response);
} catch (InvalidArgumentException|JsonException $e) {
    $logEvent(400, $e->getMessage());

    return $json(['error' => $e->getMessage()], 400);
} catch (DetectionFailedException $e) {
    $logEvent(502, $e->getMessage(), $e->modelAnswer);

    // Debug mode (local demo, synthetic data): the raw answer goes to the page so it can be inspected right there.
    return $json(DemoServices::debug()
        ? ['error' => $e->getMessage(), 'modelAnswer' => $e->modelAnswer ?? $e->getPrevious()?->getMessage()]
        : ['error' => $e->getMessage().' Set PII_DEMO_DEBUG=1 in demo/.env.local to see the model answer.'], 502);
}
