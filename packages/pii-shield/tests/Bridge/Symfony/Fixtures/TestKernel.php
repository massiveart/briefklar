<?php

declare(strict_types=1);

namespace Clarifile\PiiShield\Tests\Bridge\Symfony\Fixtures;

use Clarifile\PiiShield\Bridge\Symfony\PiiShieldBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Minimal application with FrameworkBundle and PiiShieldBundle, configured per test.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $piiShieldConfig
     */
    public function __construct(
        private readonly array $piiShieldConfig = [],
    ) {
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new PiiShieldBundle();
    }

    public function getProjectDir(): string
    {
        return sys_get_temp_dir().'/pii-shield-kernel-'.md5(serialize($this->piiShieldConfig));
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test' => true,
            'secret' => 'test',
            'http_method_override' => false,
            'cache' => ['app' => 'cache.adapter.array'],
        ]);
        $container->extension('pii_shield', $this->piiShieldConfig);

        // An application's own storage implementations, referenced by service id in the tests.
        $services = $container->services();
        $services->set('app.vault_storage', InMemoryVaultStorage::class)->public();
        $services->set('app.entity_store', InMemoryCustomEntityStore::class)->public();
        $services->set('app.lock_store', \Symfony\Component\Lock\Store\InMemoryStore::class);
        $services->set('app.lock_factory', \Symfony\Component\Lock\LockFactory::class)->args([service('app.lock_store')]);
    }
}
