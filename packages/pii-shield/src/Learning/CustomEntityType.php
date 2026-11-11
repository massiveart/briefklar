<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Learning;

use Briefklar\PiiShield\EntityType;
use Briefklar\PiiShield\Exception\LearningFailedException;

/**
 * A learned entity type: a name, a description, examples and optionally a pattern.
 *
 * With a pattern, CustomEntityDetector finds it deterministically. With or without one, the
 * model-based detector gets description and examples as few-shot guidance. Examples are stored,
 * so they must be synthetic or anonymised, never real personal data.
 */
final readonly class CustomEntityType
{
    public const int MAX_DESCRIPTION_LENGTH = 300;
    public const int MAX_EXAMPLES = 20;
    public const int MAX_EXAMPLE_LENGTH = 100;
    public const int MAX_PATTERN_LENGTH = 200;

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
        // Description and examples reach the detection model: keep them short, single-line data.
        self::assertSingleLine($description, self::MAX_DESCRIPTION_LENGTH, 'The description');
        if (\count($examples) > self::MAX_EXAMPLES || \count($counterExamples) > self::MAX_EXAMPLES) {
            throw new LearningFailedException(\sprintf('At most %d examples and %d counter-examples.', self::MAX_EXAMPLES, self::MAX_EXAMPLES));
        }
        foreach ([...$examples, ...$counterExamples] as $example) {
            self::assertSingleLine($example, self::MAX_EXAMPLE_LENGTH, 'An example');
        }
        if (null === $pattern) {
            return;
        }
        if (false === @preg_match('~(?:'.$pattern.')~u', '')) {
            throw new LearningFailedException(\sprintf('The pattern %s is not a valid regular expression.', $pattern));
        }
        self::assertSafePattern($pattern, $examples);
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
     * Builds a type from stored data (JSON file, cache); every field is checked, unknown keys are ignored.
     *
     * @param array<mixed> $data
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

    private static function assertSingleLine(string $value, int $maxLength, string $what): void
    {
        // No control characters (one line) and no angle brackets: they could end the model's data block.
        if (mb_strlen($value) > $maxLength || 1 === preg_match('/[\x00-\x1F\x7F<>]/u', $value)) {
            throw new LearningFailedException(\sprintf('%s must be one line of at most %d characters, without < and >.', $what, $maxLength));
        }
    }

    /**
     * Patterns run on every text: no constructs that can backtrack catastrophically (ReDoS).
     * PatternLearner never produces any of these; hand-written patterns are checked here.
     */
    /**
     * @param list<string> $examples
     */
    private static function assertSafePattern(string $pattern, array $examples): void
    {
        $reason = mb_strlen($pattern) > self::MAX_PATTERN_LENGTH
            ? \sprintf('longer than %d characters', self::MAX_PATTERN_LENGTH)
            : PatternGrammar::violation($pattern);
        if (null === $reason && !self::runsInBoundedTime($pattern, $examples)) {
            $reason = 'it takes too long on adversarial input';
        }
        if (null !== $reason) {
            throw new LearningFailedException(\sprintf('The pattern %s is not allowed: %s.', $pattern, $reason));
        }
    }

    /**
     * Second line of defence: the pattern must finish on long, near-miss inputs within a small backtrack budget.
     */
    /**
     * @param list<string> $examples
     */
    private static function runsInBoundedTime(string $pattern, array $examples): bool
    {
        // Fixed near-misses plus near-misses built from the type's own examples.
        $inputs = [str_repeat('a', 5000).'!', str_repeat('A', 5000).'!', str_repeat('1', 5000).'!', str_repeat('A1-', 2000).'!', str_repeat(' ', 5000).'x'];
        foreach ($examples as $example) {
            $inputs[] = str_repeat($example, intdiv(5000, max(1, mb_strlen($example)))).'!';
            $inputs[] = str_repeat(mb_substr($example, 0, -1), intdiv(5000, max(1, mb_strlen($example)))).'!';
        }
        $regex = '~(?<![\p{L}\p{N}])(?:'.$pattern.')(?![\p{L}\p{N}])~u';
        $previous = \ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '100000');
        try {
            foreach ($inputs as $input) {
                if (false === preg_match($regex, $input)) {
                    return false;
                }
            }

            return true;
        } finally {
            ini_set('pcre.backtrack_limit', false !== $previous ? $previous : '1000000');
        }
    }
}
