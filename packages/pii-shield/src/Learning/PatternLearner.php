<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Learning;

/**
 * Derives a regular expression from examples, e.g. "AB-1234/56" + "XY-9876/01" → "[A-Z]{2}-\d{4}/\d{2}".
 *
 * Each example is split into runs of upper-case letters, lower-case letters, digits, whitespace and
 * single other characters. Examples with the same shape are merged (lengths become ranges, runs that
 * are identical in all of at least two examples stay literal); different shapes become alternatives.
 *
 * Returned patterns have no delimiters and are safe inside "~…~u".
 */
final class PatternLearner
{
    /** Exception code: the examples are plain words, which cannot become a safe pattern. */
    public const int PLAIN_WORDS = 1;

    private const int MAX_SHAPES = 5;
    private const array CLASSES = ['U' => '[A-Z]', 'L' => '[a-z]', 'D' => '\d'];
    /** Used only when the examples contain umlauts, to keep patterns readable otherwise. */
    private const array UMLAUT_CLASSES = ['U' => '[A-ZÄÖÜ]', 'L' => '[a-zäöüß]'];

    /**
     * @param list<string> $examples        values that must be found
     * @param list<string> $counterExamples values that must not be found
     *
     * @throws LearningFailedException
     */
    public function learn(array $examples, array $counterExamples = []): string
    {
        $examples = array_values(array_unique(array_filter(array_map(trim(...), $examples), static fn (string $e): bool => '' !== $e)));
        if ([] === $examples) {
            throw new LearningFailedException('At least one example is needed.');
        }

        $groups = [];
        foreach ($examples as $example) {
            $tokens = self::tokenize($example);
            $groups[self::shapeOf($tokens)][] = $tokens;
        }
        if (\count($groups) > self::MAX_SHAPES) {
            throw new LearningFailedException('The examples are too different to share a pattern. Split them into several types.');
        }

        $alternatives = array_map(self::merge(...), array_values($groups));
        $pattern = 1 === \count($alternatives) ? $alternatives[0] : '(?:'.implode('|', $alternatives).')';

        if (!self::isSpecific($groups)) {
            throw new LearningFailedException('Plain words cannot be learned as a pattern; they would match ordinary text. Use model-based detection for names and words.', self::PLAIN_WORDS);
        }
        foreach ($counterExamples as $counter) {
            if (1 === preg_match('~^(?:'.$pattern.')$~u', trim($counter))) {
                throw new LearningFailedException(\sprintf('The learned pattern %s also matches the counter-example "%s". Add more examples or enter a pattern by hand.', $pattern, $counter));
            }
        }

        return $pattern;
    }

    /**
     * @return list<array{kind: string, text: string}>
     */
    private static function tokenize(string $example): array
    {
        preg_match_all('/[A-ZÄÖÜ]+|[a-zäöüß]+|\d+|\s+|./u', $example, $matches);

        return array_map(static fn (string $run): array => [
            'kind' => match (true) {
                1 === preg_match('/^[A-ZÄÖÜ]/u', $run) => 'U',
                1 === preg_match('/^[a-zäöüß]/u', $run) => 'L',
                ctype_digit($run) => 'D',
                '' === trim($run) => 'S',
                default => 'P',
            },
            'text' => $run,
        ], $matches[0]);
    }

    /**
     * @param list<array{kind: string, text: string}> $tokens
     */
    private static function shapeOf(array $tokens): string
    {
        return implode("\0", array_map(static fn (array $t): string => 'P' === $t['kind'] ? 'P'.$t['text'] : $t['kind'], $tokens));
    }

    /**
     * @param non-empty-list<list<array{kind: string, text: string}>> $examples same shape
     */
    private static function merge(array $examples): string
    {
        $pattern = '';
        foreach ($examples[0] as $i => $token) {
            $texts = array_map(static fn (array $tokens): string => $tokens[$i]['text'], $examples);
            $lengths = array_map(mb_strlen(...), $texts);

            $pattern .= match (true) {
                'P' === $token['kind'] => self::escape($token['text']),
                'S' === $token['kind'] => '\s'.self::quantifier(min($lengths), max($lengths)), // bounded on purpose
                \count($examples) > 1 && 1 === \count(array_unique($texts)) => self::escape($token['text']),
                default => self::classFor($token['kind'], $texts).self::quantifier(min($lengths), max($lengths)),
            };
        }

        return $pattern;
    }

    /**
     * @param list<string> $texts
     */
    private static function classFor(string $kind, array $texts): string
    {
        $hasUmlaut = 1 === preg_match('/[ÄÖÜäöüß]/u', implode('', $texts));

        return $hasUmlaut ? (self::UMLAUT_CLASSES[$kind] ?? self::CLASSES[$kind]) : self::CLASSES[$kind];
    }

    private static function quantifier(int $min, int $max): string
    {
        return match (true) {
            1 === $min && 1 === $max => '',
            $min === $max => '{'.$min.'}',
            default => '{'.$min.','.$max.'}',
        };
    }

    /**
     * A pattern made of letter classes only would match ordinary words.
     *
     * @param array<string, non-empty-list<list<array{kind: string, text: string}>>> $groups
     */
    private static function isSpecific(array $groups): bool
    {
        foreach ($groups as $shape => $examples) {
            $kinds = array_column($examples[0], 'kind');
            $hasConstant = \count($examples) > 1 && [] !== array_filter(
                array_keys($examples[0]),
                static fn (int $i): bool => \in_array($examples[0][$i]['kind'], ['U', 'L'], true) && 1 === \count(array_unique(array_map(static fn (array $t): string => $t[$i]['text'], $examples))),
            );
            if (!\in_array('D', $kinds, true) && !\in_array('P', $kinds, true) && !$hasConstant) {
                return false;
            }
        }

        return true;
    }

    private static function escape(string $text): string
    {
        return (string) preg_replace('/[.\\\\+*?\[\]^$(){}=!<>|:#~]/u', '\\\\$0', $text);
    }
}
