<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Demo;

use Symfony\AI\McpBundle\McpBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The pii-shield server: the demo page (/, /api/*) and the HTTP API for apps (/v1/*).
 *
 * Provider, model and store are chosen per request or from environment variables at runtime
 * (DemoServices), so changes in .env.local apply without clearing the container cache.
 */
final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new McpBundle();
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__);
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'pii-shield-server', // no sessions, CSRF tokens or signed URLs use it
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'router' => ['utf8' => true],
            'test' => 'test' === $this->environment,
        ]);

        // MCP server: STDIO (bin/console mcp:server pii_shield) and Streamable HTTP (/mcp).
        $container->extension('mcp', [
            'servers' => [
                'pii_shield' => [
                    'name' => 'pii-shield',
                    'version' => '1.0.0',
                    'description' => 'Reversible redaction, masking and learning of personal data.',
                    'instructions' => 'Before giving text with personal data to a model, call redact_text and work with the placeholders. Call restore_text with the vaultId to put the original values back into the final answer.',
                    'registry' => ['tools' => ['Briefklar\\PiiShield\\Demo\\Mcp\\']],
                    'transports' => ['stdio' => true, 'http' => true],
                    'http' => ['path' => '/mcp', 'allowed_hosts' => ['127.0.0.1', 'localhost', ...DemoServices::allowedHosts()]],
                ],
            ],
        ]);

        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set(DemoServices::class)->args(['%kernel.project_dir%/var']);
        $services->set(ServiceApi::class)->factory([service(DemoServices::class), 'serviceApi']);
        $services->set(DemoApi::class)->factory([service(DemoServices::class), 'demoApi']);
        $services->set(Controller\DemoController::class)->args(['$publicDir' => '%kernel.project_dir%/public']);
        $services->set(Controller\ServiceController::class);
        $services->set(EventSubscriber\RequestGuard::class);
        $services->set(Mcp\PiiShieldTools::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(__DIR__.'/Controller/', 'attribute');
        $routes->import('.', 'mcp');
    }
}
