<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Demo;

use Briefklar\PiiShield\Bridge\SymfonyAi\ModelDetector;
use Briefklar\PiiShield\Detector\AtSvnrDetector;
use Briefklar\PiiShield\Detector\BirthDateDetector;
use Briefklar\PiiShield\Detector\DetectorInterface;
use Briefklar\PiiShield\Detector\EmailDetector;
use Briefklar\PiiShield\Detector\FileNumberDetector;
use Briefklar\PiiShield\Detector\IbanDetector;
use Briefklar\PiiShield\Detector\LicensePlateDetector;
use Briefklar\PiiShield\Detector\PhoneDetector;
use Briefklar\PiiShield\Detector\PostalAddressDetector;
use Briefklar\PiiShield\Detector\SalutationPersonDetector;
use Briefklar\PiiShield\Detector\TaxNumberDetector;
use Briefklar\PiiShield\Entity;
use Briefklar\PiiShield\EntityExtractor;
use Briefklar\PiiShield\EntityType;
use Briefklar\PiiShield\Exception\VaultException;
use Briefklar\PiiShield\Learning\CustomEntityDetector;
use Briefklar\PiiShield\Learning\CustomEntityType;
use Briefklar\PiiShield\Learning\EntityLearner;
use Briefklar\PiiShield\Masking\MaskingStrategyInterface;
use Briefklar\PiiShield\Masking\PartialMask;
use Briefklar\PiiShield\Masking\TypeLabelMask;
use Briefklar\PiiShield\PiiMasker;
use Briefklar\PiiShield\PiiShield;
use Briefklar\PiiShield\Placeholder;
use Briefklar\PiiShield\Vault\Storage\VaultStorageInterface;
use Briefklar\PiiShield\Vault\VaultInterface;

/**
 * JSON API behind the demo page. Uses the public library API only, plus the vault storage
 * to show that it holds ciphertext.
 *
 * @phpstan-type Finding array{index: int, type: string, label: string|null, value: string, offset: int, length: int, detector: string, confidence: float, replacement: string}
 * @phpstan-type Segment array{text: string, finding: int|null}
 * @phpstan-type RestoreSegment array{text: string, status: 'plain'|'restored'|'unknown', placeholder: string|null}
 */
final class DemoApi
{
    public const int VAULT_TTL = 900;
    private const int MAX_TEXT_LENGTH = 50_000;

    /**
     * @param \Closure(string): array{VaultInterface, VaultStorageInterface} $vaults         vault and its storage by store name
     * @param \Closure(array<string, mixed>): ModelDetector                  $modelDetectors model detector from the request's model settings
     */
    public function __construct(
        private readonly \Closure $vaults,
        private readonly \Closure $modelDetectors,
        private readonly EntityLearner $learner,
    ) {
    }

    /**
     * Learns (or with dryRun previews) a type from examples; counts its matches in "text" if given.
     *
     * @param array<string, mixed> $request
     *
     * @return array{type: array{name: string, description: string, examples: list<string>, counterExamples: list<string>, pattern: string|null}, note: string|null, saved: bool, matches: int}
     */
    public function learn(array $request): array
    {
        $args = [
            \is_string($request['name'] ?? null) ? $request['name'] : '',
            \is_string($request['description'] ?? null) ? $request['description'] : '',
            self::stringList($request['examples'] ?? []),
            self::stringList($request['counterExamples'] ?? []),
            \is_string($request['pattern'] ?? null) && '' !== trim($request['pattern']) ? $request['pattern'] : null,
        ];
        $dryRun = true === ($request['dryRun'] ?? false);
        $result = $dryRun ? $this->learner->preview(...$args) : $this->learner->learn(...$args);
        $text = \is_string($request['text'] ?? null) ? $request['text'] : '';

        return [
            'type' => $result->type->toArray(),
            'note' => $result->note,
            'saved' => !$dryRun,
            'matches' => \count((new CustomEntityDetector($result->type))->detect($text)),
        ];
    }

    /**
     * @return array{types: list<array{name: string, description: string, examples: list<string>, counterExamples: list<string>, pattern: string|null}>}
     */
    public function entities(): array
    {
        return ['types' => array_map(static fn (CustomEntityType $t): array => $t->toArray(), $this->learner->types())];
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{types: list<array{name: string, description: string, examples: list<string>, counterExamples: list<string>, pattern: string|null}>}
     */
    public function forget(array $request): array
    {
        $this->learner->forget(\is_string($request['name'] ?? null) ? $request['name'] : '');

        return $this->entities();
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{findings: list<Finding>, output: string, segments: list<Segment>, vaultId: string|null, mapping: array<string, string>|null, storedPayload: string|null}
     */
    public function analyze(array $request): array
    {
        $text = self::text($request);
        $mode = $request['mode'] ?? 'reversible';
        if (!\in_array($mode, ['reversible', 'irreversible'], true)) {
            throw new \InvalidArgumentException('Unknown mode. Use "reversible" or "irreversible".');
        }
        $detectors = $this->detectors($request);
        $allowlist = self::stringList($request['allowlist'] ?? []);
        $entities = (new EntityExtractor($detectors, $allowlist))->extract($text);

        if ('irreversible' === $mode) {
            $strategies = self::strategies($request['strategies'] ?? []);
            $output = (new PiiMasker($detectors, $strategies, allowlist: $allowlist))->mask($text);
            $effective = [...PiiMasker::defaultStrategies(), ...$strategies];
            $replacements = array_map(
                static fn (Entity $e): string => ($effective[$e->type->value] ?? new TypeLabelMask())->mask($e),
                $entities,
            );

            return [...self::render($text, $entities, $replacements), 'output' => $output, 'vaultId' => null, 'mapping' => null, 'storedPayload' => null];
        }

        [$vault, $storage] = ($this->vaults)(self::store($request));
        $redaction = (new PiiShield($detectors, $vault, self::VAULT_TTL, $allowlist))->redact($text);
        $vaultId = (string) $redaction->vaultId;

        return [
            ...self::render($text, $entities, self::placeholdersIn($text, $redaction->redactedText, $entities)),
            'output' => $redaction->redactedText,
            'vaultId' => $vaultId,
            // Demo only: shows what the vault holds. A real app never sends the mapping anywhere.
            'mapping' => $redaction->vault,
            'storedPayload' => base64_encode((string) $storage->get($vaultId)),
        ];
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{text: string, segments: list<RestoreSegment>, unknown: list<string>, unused: list<string>}
     */
    public function restore(array $request): array
    {
        $text = self::text($request);
        $vaultId = \is_string($request['vaultId'] ?? null) ? $request['vaultId'] : '';

        [$vault] = ($this->vaults)(self::store($request));
        try {
            $mapping = $vault->fetch($vaultId) ?? throw new VaultException('Unknown or expired vault id.');
            $restored = (new PiiShield([], $vault))->restoreFromVault($text, $vaultId);
        } catch (VaultException $e) {
            throw new \InvalidArgumentException('The mapping has expired or does not exist. Mask the text again.', previous: $e);
        }

        // Mark placeholders with private-use characters, then split into segments.
        $marked = Placeholder::replaceAll($text, static fn (string $canonical, string $original): string => "\u{E000}{$canonical}\u{E001}{$original}\u{E002}");
        $parts = preg_split('/(\x{E000}.*?\x{E002})/u', $marked, -1, \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY);
        $segments = [];
        foreach (false === $parts ? [] : $parts as $part) {
            if (1 === preg_match('/^\x{E000}(.*?)\x{E001}(.*)\x{E002}$/us', $part, $m)) {
                $segments[] = isset($mapping[$m[1]])
                    ? ['text' => $mapping[$m[1]], 'status' => 'restored', 'placeholder' => $m[1]]
                    : ['text' => $m[2], 'status' => 'unknown', 'placeholder' => $m[1]];
            } else {
                $segments[] = ['text' => $part, 'status' => 'plain', 'placeholder' => null];
            }
        }

        return ['text' => $restored->text, 'segments' => $segments, 'unknown' => $restored->unknownPlaceholders, 'unused' => $restored->unusedPlaceholders];
    }

    /**
     * @param list<Entity> $entities
     * @param list<string> $replacements one per entity
     *
     * @return array{findings: list<Finding>, segments: list<Segment>}
     */
    private static function render(string $text, array $entities, array $replacements): array
    {
        $findings = [];
        $segments = [];
        $cursor = 0;
        foreach ($entities as $i => $entity) {
            $findings[] = [
                'index' => $i,
                'type' => $entity->type->value,
                'label' => $entity->label,
                'value' => $entity->value,
                'offset' => $entity->offset,
                'length' => $entity->length(),
                'detector' => $entity->detector,
                'confidence' => $entity->confidence,
                'replacement' => $replacements[$i],
            ];
            $segments[] = ['text' => mb_substr($text, $cursor, $entity->offset - $cursor), 'finding' => null];
            $segments[] = ['text' => $replacements[$i], 'finding' => $i];
            $cursor = $entity->offset + $entity->length();
        }
        $segments[] = ['text' => mb_substr($text, $cursor), 'finding' => null];

        return ['findings' => $findings, 'segments' => array_values(array_filter($segments, static fn (array $s): bool => '' !== $s['text']))];
    }

    /**
     * Reads the placeholder each entity was replaced with by walking the redacted text alongside the original.
     *
     * @param list<Entity> $entities
     *
     * @return list<string>
     */
    private static function placeholdersIn(string $original, string $redacted, array $entities): array
    {
        $placeholders = [];
        $originalCursor = 0;
        $redactedCursor = 0;
        foreach ($entities as $entity) {
            $redactedCursor += $entity->offset - $originalCursor;
            if (1 !== preg_match('/^⟦[A-Z_]+_\d+⟧/u', mb_substr($redacted, $redactedCursor), $m)) {
                throw new \LogicException('Redacted text does not match the detected entities.');
            }
            $placeholders[] = $m[0];
            $redactedCursor += mb_strlen($m[0]);
            $originalCursor = $entity->offset + $entity->length();
        }

        return $placeholders;
    }

    /**
     * Regex detectors, the model detector or both, limited to the selected types.
     * Each detector is asked once per request (see RequestScopedDetector).
     *
     * @param array<string, mixed> $request
     *
     * @return list<DetectorInterface>
     */
    private function detectors(array $request): array
    {
        $types = self::types($request);
        $detection = $request['detection'] ?? 'regex';
        if (!\in_array($detection, ['regex', 'model', 'both'], true)) {
            throw new \InvalidArgumentException('Unknown detection. Use "regex", "model" or "both".');
        }

        // Learned types always apply; they are not part of the built-in type selection.
        $detectors = [new RequestScopedDetector($this->learner->detector(), [EntityType::Other])];
        if ('model' !== $detection) {
            $keepAuthorityContacts = (bool) ($request['keepAuthorityContacts'] ?? true);
            foreach ($types as $type) {
                $regex = match ($type) {
                    EntityType::AtSvnr => new AtSvnrDetector(),
                    EntityType::Iban => new IbanDetector(),
                    EntityType::TaxNumber => new TaxNumberDetector(),
                    EntityType::Email => new EmailDetector($keepAuthorityContacts),
                    EntityType::Phone => new PhoneDetector($keepAuthorityContacts),
                    EntityType::BirthDate => new BirthDateDetector(),
                    EntityType::FileNumber => new FileNumberDetector(),
                    EntityType::LicensePlate => new LicensePlateDetector(),
                    EntityType::Person => new SalutationPersonDetector(),
                    EntityType::Address => new PostalAddressDetector(),
                    EntityType::IdNumber, EntityType::CreditCard, EntityType::IpAddress, EntityType::Health, EntityType::Other => null,
                };
                if (null !== $regex) {
                    $detectors[] = new RequestScopedDetector($regex, $types);
                }
            }
        }
        if ('regex' !== $detection) {
            $model = $request['model'] ?? null;
            if (!\is_array($model)) {
                throw new \InvalidArgumentException('"model" settings are required for model-based detection.');
            }
            /* @var array<string, mixed> $model */
            $detectors[] = new RequestScopedDetector(($this->modelDetectors)($model), [...$types, EntityType::Other]);
        }

        return $detectors;
    }

    /**
     * @param array<string, mixed> $request
     */
    private static function store(array $request): string
    {
        $store = $request['store'] ?? 'filesystem';

        return \is_string($store) ? $store : throw new \InvalidArgumentException('"store" must be a string.');
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return list<EntityType>
     */
    private static function types(array $request): array
    {
        if (!isset($request['types'])) {
            return EntityType::cases();
        }

        return array_map(
            static fn (string $t): EntityType => EntityType::tryFrom($t) ?? throw new \InvalidArgumentException(\sprintf('Unknown type "%s".', $t)),
            self::stringList($request['types']),
        );
    }

    /**
     * @return array<string, MaskingStrategyInterface>
     */
    private static function strategies(mixed $config): array
    {
        if (!\is_array($config)) {
            throw new \InvalidArgumentException('"strategies" must be an object.');
        }

        $strategies = [];
        foreach ($config as $type => $strategy) {
            if (null === EntityType::tryFrom((string) $type) || !\is_array($strategy)) {
                throw new \InvalidArgumentException(\sprintf('Invalid strategy for "%s".', $type));
            }
            $strategies[(string) $type] = match ($strategy['kind'] ?? null) {
                'label' => new TypeLabelMask(),
                'full' => new PartialMask(0, 0),
                'partial' => new PartialMask(self::int($strategy['keepStart'] ?? 0), self::int($strategy['keepEnd'] ?? 0)),
                default => throw new \InvalidArgumentException(\sprintf('Unknown strategy kind for "%s".', $type)),
            };
        }

        return $strategies;
    }

    /**
     * @param array<string, mixed> $request
     */
    private static function text(array $request): string
    {
        $text = $request['text'] ?? null;
        if (!\is_string($text)) {
            throw new \InvalidArgumentException('"text" must be a string.');
        }
        if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('The text is longer than %d characters.', self::MAX_TEXT_LENGTH));
        }

        return $text;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!\is_array($value) || [] !== array_filter($value, static fn (mixed $v): bool => !\is_string($v))) {
            throw new \InvalidArgumentException('Expected a list of strings.');
        }

        /* @var list<string> */
        return array_values($value);
    }

    private static function int(mixed $value): int
    {
        if (!\is_int($value) || $value < 0 || $value > 20) {
            throw new \InvalidArgumentException('Characters to keep must be an integer between 0 and 20.');
        }

        return $value;
    }
}
