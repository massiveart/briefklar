<?php

declare(strict_types=1);

namespace Clarifile\PiiShield\Detector;

/**
 * Runs a UTF-8 regex and returns matches with character (not byte) offsets.
 *
 * @internal
 */
final class RegexMatcher
{
    /**
     * @param non-empty-string $pattern must use the "u" modifier
     *
     * @return list<array{value: string, offset: int, byteOffset: int}> offset in code points, byteOffset in bytes
     */
    public static function matchAll(string $pattern, string $text, int|string $group = 0): array
    {
        if (false === preg_match_all($pattern, $text, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL)) {
            throw new \RuntimeException(\sprintf('Regex failed: %s', preg_last_error_msg()));
        }

        // Matches come in text order: count code points incrementally (linear, not per match from the start).
        $result = [];
        $byte = 0;
        $codePoint = 0;
        foreach ($matches as $match) {
            [$value, $byteOffset] = $match[$group] ?? [null, -1];
            if (null === $value || '' === $value) {
                continue;
            }
            if ($byteOffset < $byte) { // a group can start before the previous match's group
                $byte = 0;
                $codePoint = 0;
            }
            $codePoint += mb_strlen(substr($text, $byte, $byteOffset - $byte));
            $byte = $byteOffset;
            $result[] = ['value' => $value, 'offset' => $codePoint, 'byteOffset' => $byteOffset];
        }

        return $result;
    }
}
