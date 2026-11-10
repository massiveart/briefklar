<?php

declare(strict_types=1);

/*
 * Front controller of the pii-shield server: demo page (/, /api) and HTTP API for apps (/v1).
 * Locally: `make demo`, then http://127.0.0.1:8088. In Docker: service mode, /v1 only.
 */

use Briefklar\PiiShield\Demo\DemoServices;
use Briefklar\PiiShield\Demo\Kernel;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;

// Standalone (Docker): the server's own vendor/; locally (`make demo`): the package's vendor/.
require is_file(dirname(__DIR__).'/vendor/autoload.php') ? dirname(__DIR__).'/vendor/autoload.php' : dirname(__DIR__, 2).'/vendor/autoload.php';

// Provider keys and URLs: demo/.env.local (git-ignored), see demo/.env.dist. Real environment variables win.
if (is_file(dirname(__DIR__).'/.env.local')) {
    (new Dotenv())->load(dirname(__DIR__).'/.env.local');
}

// PHP's built-in server serves the page's assets directly (not in service mode).
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH);
if ('cli-server' === \PHP_SAPI && is_string($path) && '/' !== $path && is_file(__DIR__.$path) && !DemoServices::serviceOnly()) {
    return false;
}

$env = $_SERVER['APP_ENV'] ?? 'prod';
$kernel = new Kernel(is_string($env) ? $env : 'prod', 'dev' === $env);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
