<?php

declare(strict_types=1);

namespace Briefklar\PiiShield;

/**
 * Placeholder format "⟦TYPE_n⟧" (see docs/adr/0003-reversible-placeholders.md).
 *
 * Parsing is tolerant to what models do to placeholders: case changes, inner whitespace,
 * space instead of underscore, markdown-escaped underscores and ASCII "[[...]]" brackets.
 */
final class Placeholder
{
    private const string OPEN = '⟦';
    private const string CLOSE = '⟧';

    private const string TOLERANT_PATTERN = '/(?:⟦|\[\[)\s*(?<type>[A-Za-z]+(?:(?:\\\\?_|\s+)[A-Za-z]+)*)(?:\\\\?_|\s+)(?<number>\d+)\s*(?:⟧|\]\])/u';

    public static function format(EntityType $type, int $number): string
    {
        return self::OPEN.$type->value.'_'.$number.self::CLOSE;
    }

    /**
     * Calls $replace with the canonical form of every placeholder found, returns the resulting text.
     *
     * @param callable(string $canonical, string $original): string $replace
     */
    public static function replaceAll(string $text, callable $replace): string
    {
        return (string) preg_replace_callback(
            self::TOLERANT_PATTERN,
            static function (array $match) use ($replace): string {
                $type = strtoupper((string) preg_replace('/(?:\\\\?_|\s+)/', '_', $match['type']));

                return $replace(self::OPEN.$type.'_'.$match['number'].self::CLOSE, $match[0]);
            },
            $text,
        );
    }
}
