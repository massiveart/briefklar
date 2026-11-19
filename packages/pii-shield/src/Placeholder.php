<?php

declare(strict_types=1);

namespace Clarifile\PiiShield;

/**
 * Placeholder format "⟦TYPE_n⟧" (see docs/adr/0003-reversible-placeholders.md).
 *
 * Parsing is tolerant to what models do to placeholders: case changes, inner whitespace,
 * space instead of underscore, markdown-escaped underscores and ASCII "[[...]]" brackets.
 * Type names may contain digits (learned types such as "VN2_NUMBER"); the last number is the counter.
 */
final class Placeholder
{
    private const string OPEN = '⟦';
    private const string CLOSE = '⟧';

    private const string TOLERANT_PATTERN = '/(?:⟦|\[\[)\s*(?<type>[A-Za-z][A-Za-z0-9]*(?:(?:\\\\?_|\s+)[A-Za-z0-9]+)*)(?:\\\\?_|\s+)(?<number>\d+)\s*(?:⟧|\]\])/u';

    public static function format(EntityType $type, int $number, ?string $label = null): string
    {
        return self::OPEN.($label ?? $type->value).'_'.$number.self::CLOSE;
    }

    /**
     * Calls $replace with the canonical form of every placeholder found, returns the resulting text.
     *
     * @param callable(string $canonical, string $original): string $replace
     */
    public static function replaceAll(string $text, callable $replace): string
    {
        $result = preg_replace_callback(
            self::TOLERANT_PATTERN,
            static function (array $match) use ($replace): string {
                $type = strtoupper((string) preg_replace('/(?:\\\\?_|\s+)/', '_', $match['type']));

                return $replace(self::OPEN.$type.'_'.$match['number'].self::CLOSE, $match[0]);
            },
            $text,
        );

        // Fail loudly: "(string) null" would silently turn the whole answer into an empty string.
        return $result ?? throw new \RuntimeException(\sprintf('Placeholders could not be parsed: %s', preg_last_error_msg()));
    }
}
