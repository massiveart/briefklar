<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Learning;

use Briefklar\PiiShield\EntityType;

/**
 * A learned entity type: a name, a description, examples and optionally a pattern.
 *
 * With a pattern, CustomEntityDetector finds it deterministically. With or without one, the
 * model-based detector gets description and examples as few-shot guidance. Examples are stored,
 * so they must be synthetic or anonymised, never real personal data.
 */
final readonly class CustomEntityType
{
    /**
     * @param string       $name            UPPER_SNAKE_CASE, becomes the placeholder name (⟦NAME_1⟧)
     * @param list<string> $examples        values that are this type
     * @param list<string> $counterExamples similar values that are not
     * @param string|null  $pattern         regular expression without delimiters, see PatternLearner
     *
     * @throws LearningFailedException
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $examples,
        public array $counterExamples = [],
        public ?string $pattern = null,
    ) {
        if (1 !== preg_match('/^[A-Z][A-Z0-9_]{1,39}$/', $name)) {
            throw new LearningFailedException('The name must be UPPER_SNAKE_CASE, 2 to 40 characters, e.g. INSURANCE_NUMBER.');
        }
        if (null !== EntityType::tryFrom($name)) {
            throw new LearningFailedException(\sprintf('"%s" is a built-in type. Choose another name.', $name));
        }
        if ([] === $examples) {
            throw new LearningFailedException('At least one example is needed.');
        }
        if (null === $pattern) {
            return;
        }
        if (false === @preg_match('~(?:'.$pattern.')~u', '')) {
            throw new LearningFailedException(\sprintf('The pattern %s is not a valid regular expression.', $pattern));
        }
        foreach ($examples as $example) {
            if (1 !== preg_match('~^(?:'.$pattern.')$~u', $example)) {
                throw new LearningFailedException(\sprintf('The pattern %s does not match the example "%s".', $pattern, $example));
            }
        }
        foreach ($counterExamples as $counter) {
            if (1 === preg_match('~^(?:'.$pattern.')$~u', $counter)) {
                throw new LearningFailedException(\sprintf('The pattern %s also matches the counter-example "%s".', $pattern, $counter));
            }
        }
    }

    /**
     * The pattern as a whole-word search regex, or null for model-only types.
     *
     * @return non-empty-string|null
     */
    public function regex(): ?string
    {
        return null === $this->pattern ? null : '~(?<![\p{L}\p{N}])(?:'.$this->pattern.')(?![\p{L}\p{N}])~u';
    }

    /**
     * @return array{name: string, description: string, examples: list<string>, counterExamples: list<string>, pattern: string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'examples' => $this->examples,
            'counterExamples' => $this->counterExamples,
            'pattern' => $this->pattern,
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws LearningFailedException
     */
    public static function fromArray(array $data): self
    {
        $strings = static function (mixed $list): array {
            if (!\is_array($list)) {
                return [];
            }

            return array_values(array_filter($list, \is_string(...)));
        };

        return new self(
            \is_string($data['name'] ?? null) ? $data['name'] : '',
            \is_string($data['description'] ?? null) ? $data['description'] : '',
            $strings($data['examples'] ?? []),
            $strings($data['counterExamples'] ?? []),
            \is_string($data['pattern'] ?? null) && '' !== $data['pattern'] ? $data['pattern'] : null,
        );
    }
}
