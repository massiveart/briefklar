<?php

declare(strict_types=1);

/*
 * Front controller for the local demo: `composer demo` or `make demo`, then open http://127.0.0.1:8088.
 * Runs on PHP's built-in server only. Nothing is sent to any external service.
 */

use Briefklar\PiiShield\Demo\DemoApi;
use Briefklar\PiiShield\Vault\EncryptedVault;
use Briefklar\PiiShield\Vault\Storage\Psr6CacheStorage;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH);
$path = is_string($path) ? $path : '/';

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
    echo json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

    return true;
};

if ('/api/samples' === $path) {
    $samples = [];
    foreach (glob(dirname(__DIR__, 4).'/fixtures/letters/*.txt') ?: [] as $file) {
        $samples[] = ['name' => basename($file, '.txt'), 'text' => (string) file_get_contents($file)];
    }

    return $json($samples);
}

if (!in_array($path, ['/api/analyze', '/api/restore'], true)) {
    return $json(['error' => 'Not found.'], 404);
}
if ('POST' !== ($_SERVER['REQUEST_METHOD'] ?? 'GET')) {
    return $json(['error' => 'Use POST.'], 405);
}

$varDir = dirname(__DIR__).'/var';
if (!is_dir($varDir)) {
    mkdir($varDir, 0700, true);
}
$keyFile = $varDir.'/vault.key';
if (!is_file($keyFile)) {
    file_put_contents($keyFile, base64_encode(EncryptedVault::generateKey()));
    chmod($keyFile, 0600);
}
$storage = new Psr6CacheStorage(new FilesystemAdapter('vault', DemoApi::VAULT_TTL, $varDir.'/cache'));
$api = new DemoApi(new EncryptedVault($storage, (string) base64_decode((string) file_get_contents($keyFile), true)), $storage);

try {
    $request = json_decode((string) file_get_contents('php://input'), true, 16, \JSON_THROW_ON_ERROR);
    if (!is_array($request)) {
        throw new InvalidArgumentException('Expected a JSON object.');
    }
    /** @var array<string, mixed> $request */
    $response = '/api/analyze' === $path ? $api->analyze($request) : $api->restore($request);

    return $json($response);
} catch (InvalidArgumentException|JsonException $e) {
    return $json(['error' => $e->getMessage()], 400);
}
