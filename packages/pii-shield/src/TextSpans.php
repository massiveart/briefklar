<?php

declare(strict_types=1);

namespace Briefklar\PiiShield;

/**
 * Replaces entity spans in a text in one pass. Entity offsets are code points; the text is split
 * into code points once instead of calling mb_substr() per entity (which scans from the start).
 *
 * @internal
 */
final class TextSpans
{
    /**
     * @param list<Entity>             $entities    sorted by offset, not overlapping
     * @param \Closure(Entity): string $replacement
     */
    public static function replace(string $text, array $entities, \Closure $replacement): string
    {
        if ([] === $entities) {
            return $text;
        }

        $chars = mb_str_split($text);
        $result = '';
        $cursor = 0;
        foreach ($entities as $entity) {
            $result .= implode('', \array_slice($chars, $cursor, $entity->offset - $cursor)).$replacement($entity);
            $cursor = $entity->offset + $entity->length();
        }

        return $result.implode('', \array_slice($chars, $cursor));
    }
}
