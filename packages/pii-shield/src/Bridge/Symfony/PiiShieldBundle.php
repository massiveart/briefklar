<?php

declare(strict_types=1);

namespace Clarifile\PiiShield\Bridge\Symfony;

use Clarifile\PiiShield\Bridge\SymfonyAi\ModelDetector;
use Clarifile\PiiShield\Bridge\SymfonyAi\ModelLocation;
use Clarifile\PiiShield\Bridge\SymfonyAi\PiiShieldProcessor;
use Clarifile\PiiShield\Detector\AtSvnrDetector;
use Clarifile\PiiShield\Detector\BirthDateDetector;
use Clarifile\PiiShield\Detector\EmailDetector;
use Clarifile\PiiShield\Detector\FileNumberDetector;
use Clarifile\PiiShield\Detector\IbanDetector;
use Clarifile\PiiShield\Detector\LicensePlateDetector;
use Clarifile\PiiShield\Detector\PhoneDetector;
use Clarifile\PiiShield\Detector\PostalAddressDetector;
use Clarifile\PiiShield\Detector\SalutationPersonDetector;
use Clarifile\PiiShield\Detector\TaxNumberDetector;
use Clarifile\PiiShield\EntityType;
use Clarifile\PiiShield\Learning\CacheCustomEntityStore;
use Clarifile\PiiShield\Learning\CustomEntityStoreInterface;
use Clarifile\PiiShield\Learning\EntityLearner;
use Clarifile\PiiShield\Learning\JsonFileCustomEntityStore;
use Clarifile\PiiShield\Learning\LearnedTypesDetector;
use Clarifile\PiiShield\Learning\LockingCustomEntityStore;
use Clarifile\PiiShield\Masking\TypeLabelMask;
use Clarifile\PiiShield\PiiMasker;
use Clarifile\PiiShield\PiiMaskerInterface;
use Clarifile\PiiShield\PiiShield;
use Clarifile\PiiShield\PiiShieldInterface;
use Clarifile\PiiShield\Vault\EncryptedVault;
use Clarifile\PiiShield\Vault\Storage\Psr6CacheStorage;
use Clarifile\PiiShield\Vault\Storage\VaultStorageInterface;
use Clarifile\PiiShield\Vault\VaultInterface;
use Clarifile\PiiShield\VaultAwarePiiShieldInterface;
use Symfony\AI\Agent\InputProcessorInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Lock\LockFactory;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/**
 * Symfony integration: config/packages/pii_shield.yaml, services prefixed "pii_shield.".
 *
 * Add your own detectors by tagging services "pii_shield.detector".
 */
final class PiiShieldBundle extends AbstractBundle
{
    protected string $extensionAlias = 'pii_shield';

    /** Built-in pattern detectors per type; types without one are found by the model only. */
    private const array DETECTORS = [
        'SVNR' => AtSvnrDetector::class,
        'IBAN' => IbanDetector::class,
        'TAX_NUMBER' => TaxNumberDetector::class,
        'EMAIL' => EmailDetector::class,
        'PHONE' => PhoneDetector::class,
        'BIRTH_DATE' => BirthDateDetector::class,
        'FILE_NUMBER' => FileNumberDetector::class,
        'LICENSE_PLATE' => LicensePlateDetector::class,
        'PERSON' => SalutationPersonDetector::class,
        'ADDRESS' => PostalAddressDetector::class,
    ];

    public function configure(DefinitionConfigurator $definition): void
    {
        $types = array_map(static fn (EntityType $t): string => $t->value, EntityType::cases());
        $locations = array_map(static fn (ModelLocation $l): string => $l->value, ModelLocation::cases());

        $definition->rootNode()
            ->children()
                ->arrayNode('types')
                    ->info('Built-in entity types to detect. Default: all.')
                    ->enumPrototype()->values($types)->end()
                    ->defaultValue($types)
                ->end()
                ->booleanNode('keep_authority_contacts')
                    ->info('Keep e-mail and phone of the sending authority (needed to act on a letter).')
                    ->defaultTrue()
                ->end()
                ->arrayNode('allowlist')
                    ->info('Values that are never redacted (exact match).')
                    ->scalarPrototype()->end()
                ->end()
                ->arrayNode('model')
                    ->info('Detection by a model via Symfony AI Platform, in addition to the patterns.')
                    ->canBeEnabled()
                    ->children()
                        // Required only when enabled (validate() below), so "enabled: false" works on its own.
                        ->scalarNode('platform')->info('Service id of a Symfony AI platform, e.g. "ai.platform.ollama". Required when enabled.')->defaultNull()->end()
                        ->scalarNode('model')->info('Model name, e.g. "qwen3:8b". Required when enabled.')->defaultNull()->end()
                        ->enumNode('location')->values($locations)->defaultValue(ModelLocation::Local->value)->end()
                        ->booleanNode('acknowledge_external_transfer')
                            ->info('Detection sends unredacted text to the model. Required for "external".')
                            ->defaultFalse()
                        ->end()
                        ->arrayNode('options')->info('Passed to the platform, e.g. {think: false}.')->variablePrototype()->end()->end()
                        ->integerNode('chunk_size')
                            ->info('Characters per model call; longer texts are split along paragraphs and sent concurrently.')
                            ->min(100)->defaultValue(ModelDetector::DEFAULT_CHUNK_SIZE)
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $m): bool => true === $m['enabled'] && (null === $m['platform'] || '' === $m['platform']))
                        ->thenInvalid('Set "platform" (a Symfony AI platform service id) to enable model detection.')
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $m): bool => true === $m['enabled'] && (null === $m['model'] || '' === $m['model']))
                        ->thenInvalid('Set "model" (the model name) to enable model detection.')
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $m): bool => true === $m['enabled'] && ModelLocation::External->value === $m['location'] && true !== $m['acknowledge_external_transfer'])
                        ->thenInvalid('An external detection model receives unredacted text: set "acknowledge_external_transfer: true" (requires a data processing agreement).')
                    ->end()
                ->end()
                ->arrayNode('vault')
                    ->info('Store mappings encrypted to restore in a later request.')
                    ->canBeEnabled()
                    ->children()
                        ->scalarNode('key')->info('32 raw bytes, e.g. "%env(base64:PII_SHIELD_VAULT_KEY)%" (create with EncryptedVault::generateKey()). Required when enabled. Keep the key in an env var: a literal key is parsed for %parameters% and must escape % as %%.')->defaultNull()->end()
                        ->scalarNode('cache_pool')->info('PSR-6 pool service id; default "cache.app".')->defaultNull()->end()
                        ->scalarNode('storage')->info('Service id of your own VaultStorageInterface (database, S3, ...) instead of a PSR-6 pool. Payloads arrive encrypted.')->defaultNull()->end()
                        ->integerNode('ttl')->min(1)->defaultValue(PiiShield::DEFAULT_VAULT_TTL)->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $v): bool => true === $v['enabled'] && (null === $v['key'] || '' === $v['key']))
                        ->thenInvalid('Set "key" (32 raw bytes) to enable the vault.')
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $v): bool => null !== $v['storage'] && null !== $v['cache_pool'])
                        ->thenInvalid('Set either "storage" or "cache_pool" for the vault, not both.')
                    ->end()
                ->end()
                ->arrayNode('learning')
                    ->info('Entity types learned from examples.')
                    ->canBeDisabled()
                    ->children()
                        ->scalarNode('path')->defaultValue('%kernel.project_dir%/var/pii_shield/learned_types.json')->end()
                        ->scalarNode('cache_pool')->info('Store in this PSR-6 pool instead of the JSON file.')->defaultNull()->end()
                        ->scalarNode('store')->info('Service id of your own CustomEntityStoreInterface instead of the JSON file.')->defaultNull()->end()
                        ->scalarNode('lock')->info('Service id of a Symfony\\Component\\Lock\\LockFactory (symfony/lock) to lock writes, for several processes learning at once. Its store must be shared by all of them.')->defaultNull()->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $l): bool => null !== $l['lock'] && !class_exists(LockFactory::class))
                        ->thenInvalid('"lock" needs symfony/lock: composer require symfony/lock')
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $l): bool => null !== $l['store'] && null !== $l['cache_pool'])
                        ->thenInvalid('Set either "store" or "cache_pool" for learning, not both.')
                    ->end()
                ->end()
                ->arrayNode('agent_processor')
                    ->info('Redact input and restore output of Symfony AI agents.')
                    ->canBeDisabled()
                    ->children()
                        ->arrayNode('agents')->info('Agent names; empty = all agents.')->scalarPrototype()->end()->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array<mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        /** @var array{types: list<string>, keep_authority_contacts: bool, allowlist: list<string>, model: array{enabled: bool, platform?: string, model?: string, location?: string, acknowledge_external_transfer?: bool, options?: array<string, mixed>, chunk_size?: int}, vault: array{enabled: bool, key?: string, cache_pool?: string|null, storage?: string|null, ttl?: int}, learning: array{enabled: bool, path: string, cache_pool: string|null, store?: string|null, lock?: string|null}, agent_processor: array{enabled: bool, agents: list<string>}} $config */
        $services = $container->services();

        foreach ($config['types'] as $type) {
            $class = self::DETECTORS[$type] ?? null;
            if (null === $class) {
                continue;
            }
            $detector = $services->set('pii_shield.detector.'.strtolower($type), $class)->tag('pii_shield.detector');
            if (EmailDetector::class === $class || PhoneDetector::class === $class) {
                $detector->args([$config['keep_authority_contacts']]);
            }
        }

        $learnedStore = null;
        if ($config['learning']['enabled']) {
            $learnedStore = service('pii_shield.learned_types');
            $lock = $config['learning']['lock'] ?? null;
            $storeId = null === $lock ? 'pii_shield.learned_types' : 'pii_shield.learned_types.unlocked';
            match (true) {
                null !== ($config['learning']['store'] ?? null) => $services->alias($storeId, $config['learning']['store']),
                null !== $config['learning']['cache_pool'] => $services->set($storeId, CacheCustomEntityStore::class)->args([service($config['learning']['cache_pool'])]),
                default => $services->set($storeId, JsonFileCustomEntityStore::class)->args([$config['learning']['path']]),
            };
            if (null !== $lock) {
                $services->set('pii_shield.learned_types', LockingCustomEntityStore::class)->args([service($storeId), service($lock)]);
            }
            $services->alias(CustomEntityStoreInterface::class, 'pii_shield.learned_types');
            $services->set('pii_shield.learner', EntityLearner::class)->args([$learnedStore]);
            $services->alias(EntityLearner::class, 'pii_shield.learner')->public();
            $services->set('pii_shield.detector.learned', LearnedTypesDetector::class)->args([$learnedStore])->tag('pii_shield.detector');
        }

        if ($config['model']['enabled']) {
            $services->set('pii_shield.detector.model', ModelDetector::class)
                ->args([
                    service($config['model']['platform'] ?? ''),
                    $config['model']['model'] ?? '',
                    ModelLocation::from($config['model']['location'] ?? ModelLocation::Local->value),
                    $config['model']['acknowledge_external_transfer'] ?? false,
                    $config['model']['options'] ?? [],
                    $learnedStore,
                    $config['model']['chunk_size'] ?? ModelDetector::DEFAULT_CHUNK_SIZE,
                    $config['keep_authority_contacts'],
                ])
                ->tag('pii_shield.detector');
        }

        $vault = null;
        if ($config['vault']['enabled']) {
            null !== ($config['vault']['storage'] ?? null)
                ? $services->alias('pii_shield.vault.storage', $config['vault']['storage'])
                : $services->set('pii_shield.vault.storage', Psr6CacheStorage::class)->args([service($config['vault']['cache_pool'] ?? 'cache.app')]);
            $services->alias(VaultStorageInterface::class, 'pii_shield.vault.storage');
            $services->set('pii_shield.vault', EncryptedVault::class)
                ->args([service('pii_shield.vault.storage'), $config['vault']['key'] ?? '']);
            $services->alias(VaultInterface::class, 'pii_shield.vault');
            $vault = service('pii_shield.vault');
        }

        $services->set('pii_shield.shield', PiiShield::class)
            ->args([tagged_iterator('pii_shield.detector'), $vault, $config['vault']['ttl'] ?? PiiShield::DEFAULT_VAULT_TTL, $config['allowlist']]);
        $services->alias(PiiShieldInterface::class, 'pii_shield.shield')->public();
        $services->alias(VaultAwarePiiShieldInterface::class, 'pii_shield.shield')->public();

        $services->set('pii_shield.masker', PiiMasker::class)
            ->args([tagged_iterator('pii_shield.detector'), [], service('pii_shield.masker.fallback'), $config['allowlist']]);
        $services->set('pii_shield.masker.fallback', TypeLabelMask::class);
        $services->alias(PiiMaskerInterface::class, 'pii_shield.masker')->public();

        if ($config['agent_processor']['enabled'] && interface_exists(InputProcessorInterface::class)) {
            $processor = $services->set('pii_shield.agent_processor', PiiShieldProcessor::class)->args([service('pii_shield.shield')]);
            // Input last (also redacts what other processors add, e.g. memory), output first.
            foreach ([] === $config['agent_processor']['agents'] ? [null] : $config['agent_processor']['agents'] as $agent) {
                $agentAttribute = null === $agent ? [] : ['agent' => 'ai.agent.'.$agent];
                $processor->tag('ai.agent.input_processor', [...$agentAttribute, 'priority' => -100]);
                $processor->tag('ai.agent.output_processor', [...$agentAttribute, 'priority' => 100]);
            }
        }
    }
}
