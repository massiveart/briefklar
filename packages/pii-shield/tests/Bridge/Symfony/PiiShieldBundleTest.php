<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Tests\Bridge\Symfony;

use Briefklar\PiiShield\Bridge\Symfony\PiiShieldBundle;
use Briefklar\PiiShield\Bridge\SymfonyAi\PiiShieldProcessor;
use Briefklar\PiiShield\Learning\EntityLearner;
use Briefklar\PiiShield\PiiMaskerInterface;
use Briefklar\PiiShield\PiiShieldInterface;
use Briefklar\PiiShield\Tests\Bridge\Symfony\Fixtures\TestKernel;
use Briefklar\PiiShield\Vault\EncryptedVault;
use Briefklar\PiiShield\VaultAwarePiiShieldInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

final class PiiShieldBundleTest extends TestCase
{
    private ?TestKernel $kernel = null;

    protected function tearDown(): void
    {
        if (null !== $this->kernel) {
            $dir = $this->kernel->getProjectDir();
            $this->kernel->shutdown();
            (new Filesystem())->remove($dir);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function container(array $config = []): ContainerInterface
    {
        $this->kernel = new TestKernel($config);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private static function service(ContainerInterface $container, string $id): object
    {
        $service = $container->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }

    public function testDefaultsGiveAWorkingShieldAndMasker(): void
    {
        $container = $this->container();

        $shield = self::service($container, PiiShieldInterface::class);
        self::assertSame('SVNR ⟦SVNR_1⟧', $shield->redact('SVNR 4568 150392')->redactedText);
        self::assertSame('SVNR [SVNR]', self::service($container, PiiMaskerInterface::class)->mask('SVNR 4568 150392'));
    }

    public function testTypesAllowlistAndAuthorityContactsAreConfigurable(): void
    {
        $container = $this->container(['types' => ['IBAN'], 'allowlist' => ['AT61 1904 3002 3457 3201']]);

        $text = 'SVNR 4568 150392, IBAN AT61 1904 3002 3457 3201, IBAN DE89 3704 0044 0532 0130 00';
        self::assertSame('SVNR 4568 150392, IBAN AT61 1904 3002 3457 3201, IBAN ⟦IBAN_1⟧', self::service($container, PiiShieldInterface::class)->redact($text)->redactedText);
    }

    public function testVaultRestoresInALaterRequest(): void
    {
        $container = $this->container(['vault' => ['key' => EncryptedVault::generateKey(), 'ttl' => 600]]);
        $shield = self::service($container, VaultAwarePiiShieldInterface::class);

        $vaultId = $shield->redact('SVNR 4568 150392')->vaultId;

        self::assertNotNull($vaultId);
        self::assertSame('4568 150392', $shield->restoreFromVault('⟦SVNR_1⟧', $vaultId)->text);
    }

    public function testLearnedTypesAreDetected(): void
    {
        $container = $this->container();
        $learner = self::service($container, EntityLearner::class);

        $learner->learn('INSURANCE_NUMBER', 'Polizzennummer', ['VN-2026-000417', 'VN-2025-998812']);

        self::assertSame('Polizze ⟦INSURANCE_NUMBER_1⟧', self::service($container, PiiShieldInterface::class)->redact('Polizze VN-2024-123456')->redactedText);
        self::assertFileExists($this->kernel?->getProjectDir().'/var/pii_shield/learned_types.json');
    }

    public function testLearningCanBeDisabled(): void
    {
        $container = $this->container(['learning' => false]);

        self::assertFalse($container->has(EntityLearner::class));
    }

    public function testAgentProcessorIsTaggedForSymfonyAi(): void
    {
        $builder = $this->load(['agent_processor' => ['agents' => ['letters']]]);

        $definition = $builder->getDefinition('pii_shield.agent_processor');
        self::assertSame(PiiShieldProcessor::class, $definition->getClass());
        self::assertSame([['agent' => 'ai.agent.letters', 'priority' => -100]], $definition->getTag('ai.agent.input_processor'));
        self::assertSame([['agent' => 'ai.agent.letters', 'priority' => 100]], $definition->getTag('ai.agent.output_processor'));
    }

    public function testAgentProcessorAppliesToAllAgentsByDefault(): void
    {
        $definition = $this->load([])->getDefinition('pii_shield.agent_processor');

        self::assertSame([['priority' => -100]], $definition->getTag('ai.agent.input_processor'));
    }

    public function testModelDetectorIsWiredToTheConfiguredPlatform(): void
    {
        $builder = $this->load(['model' => ['platform' => 'ai.platform.ollama', 'model' => 'qwen3:4b-instruct', 'options' => ['think' => false]]]);

        $definition = $builder->getDefinition('pii_shield.detector.model');
        self::assertSame('ai.platform.ollama', (string) $definition->getArgument(0));
        self::assertSame('qwen3:4b-instruct', $definition->getArgument(1));
        self::assertArrayHasKey('pii_shield.detector', $definition->getTags());
    }

    public function testExternalModelsNeedAnAcknowledgement(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('acknowledge_external_transfer');

        $this->load(['model' => ['platform' => 'ai.platform.anthropic', 'model' => 'claude-sonnet-5-5', 'location' => 'external']]);
    }

    public function testOptionsCanBeDisabledExplicitly(): void
    {
        $builder = $this->load(['model' => ['enabled' => false], 'vault' => ['enabled' => false], 'learning' => ['enabled' => false]]);

        self::assertFalse($builder->hasDefinition('pii_shield.detector.model'));
        self::assertFalse($builder->hasDefinition('pii_shield.vault'));
        self::assertFalse($builder->hasDefinition('pii_shield.learner'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function enabledOptionsWithoutRequiredValues(): iterable
    {
        yield 'model without platform' => [['model' => ['enabled' => true, 'model' => 'qwen3:8b']], 'platform'];
        yield 'model without model' => [['model' => ['platform' => 'ai.platform.ollama']], 'model'];
        yield 'vault without key' => [['vault' => ['enabled' => true]], 'key'];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('enabledOptionsWithoutRequiredValues')]
    public function testEnabledOptionsStillRequireTheirValues(array $config, string $missing): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($missing);

        $this->load($config);
    }

    public function testUnknownTypesAreRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->load(['types' => ['SHOE_SIZE']]);
    }

    /**
     * Loads the extension into a plain builder to inspect definitions and tags.
     *
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $builder = new ContainerBuilder();
        $builder->setParameter('kernel.project_dir', sys_get_temp_dir());
        $builder->setParameter('kernel.environment', 'test');
        $builder->setParameter('kernel.build_dir', sys_get_temp_dir());
        $builder->setParameter('kernel.debug', false);
        $extension = (new PiiShieldBundle())->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([$config], $builder);

        return $builder;
    }
}
