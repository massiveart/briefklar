<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    /**
     * symfony/ai-bundle 1.0.0-BETA1 keeps its #[IsGrantedTool] listener as soon as symfony/security-core
     * is installed, but the listener needs security.authorization_checker from SecurityBundle. The
     * form CSRF protection pulls in security-core without SecurityBundle, and we use no #[IsGrantedTool]:
     * drop the listener then (docs/upstream-issues.md).
     */
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition('ai.security.is_granted_attribute_listener') && !class_exists('Symfony\\Bundle\\SecurityBundle\\SecurityBundle')) {
            $container->removeDefinition('ai.security.is_granted_attribute_listener');
        }
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
