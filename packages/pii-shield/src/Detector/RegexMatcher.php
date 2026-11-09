<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Detector;

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
     * @return list<array{value: string, offset: int}>
     */
    public static function matchAll(string $pattern, string $text, int|string $group = 0): array
    {
        if (false === preg_match_all($pattern, $text, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL)) {
            throw new \RuntimeException(\sprintf('Regex failed: %s', preg_last_error_msg()));
        }

        $result = [];
        foreach ($matches as $match) {
            [$value, $byteOffset] = $match[$group] ?? [null, -1];
            if (null === $value || '' === $value) {
                continue;
            }
            $result[] = ['value' => $value, 'offset' => mb_strlen(substr($text, 0, $byteOffset))];
        }

        return $result;
    }

    /**
     * The line (between newlines) around a character offset.
     */
    public static function lineAround(string $text, int $offset): string
    {
        $before = mb_substr($text, 0, $offset);
        $start = mb_strrpos($before, "\n");
        $start = false === $start ? 0 : $start + 1;
        $end = mb_strpos($text, "\n", $offset);
        $end = false === $end ? mb_strlen($text) : $end;

        return mb_substr($text, $start, $end - $start);
    }
}
