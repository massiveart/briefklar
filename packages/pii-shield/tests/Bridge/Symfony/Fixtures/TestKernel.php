<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Tests\Bridge\Symfony\Fixtures;

use Briefklar\PiiShield\Bridge\Symfony\PiiShieldBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

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
    }
}
