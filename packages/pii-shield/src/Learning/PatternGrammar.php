<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Learning;

/**
 * Whitelist grammar for hand-written patterns, parsed left to right (no regex over the regex).
 *
 * Allowed: literals, escaped metacharacters, \d \D \w \W \s \S, ".", character classes "[...]"
 * (literals, ranges, the same escapes), alternation "|", groups "(...)" and "(?:...)" WITHOUT a
 * quantifier, and quantifiers * + ? {n} {n,} {n,m} directly after a single atom.
 *
 * Without repeated groups there is no nested repetition, so no catastrophic backtracking.
 * Everything else (anchors, backreferences, lookarounds, \Q…\E, \p{…}, lazy/possessive
 * quantifiers, inline flags) is rejected.
 *
 * @internal
 */
final class PatternGrammar
{
    private const string ESCAPABLE = '.^$|()[]{}*+?\\-/~#';
    private const string CLASS_SHORTHANDS = 'dDwWsS';

    /**
     * @return string|null why the pattern is not allowed, or null if it is
     */
    public static function violation(string $pattern): ?string
    {
        $chars = mb_str_split($pattern);
        $count = \count($chars);
        $depth = 0;
        $lastWasAtom = false;   // a quantifier may follow
        $afterGroup = false;    // a quantifier here would repeat a group

        for ($i = 0; $i < $count; ++$i) {
            $c = $chars[$i];

            switch ($c) {
                case '\\':
                    $next = $chars[$i + 1] ?? null;
                    if (null === $next || (!str_contains(self::CLASS_SHORTHANDS, $next) && !str_contains(self::ESCAPABLE, $next))) {
                        return \sprintf('the escape \\%s is not allowed (use \\d \\w \\s or escape a special character)', $next ?? '');
                    }
                    ++$i;
                    $lastWasAtom = true;
                    $afterGroup = false;
                    break;

                case '[':
                    $end = self::classEnd($chars, $i);
                    if (null === $end) {
                        return 'a character class is not closed or contains an escape that is not allowed';
                    }
                    $i = $end;
                    $lastWasAtom = true;
                    $afterGroup = false;
                    break;

                case '(':
                    if ('?' === ($chars[$i + 1] ?? null)) {
                        if (':' !== ($chars[$i + 2] ?? null)) {
                            return 'only (…) and (?:…) groups are allowed (no lookarounds, flags or named groups)';
                        }
                        $i += 2;
                    }
                    ++$depth;
                    $lastWasAtom = false;
                    $afterGroup = false;
                    break;

                case ')':
                    if (0 === $depth--) {
                        return 'unbalanced parenthesis';
                    }
                    $lastWasAtom = false;
                    $afterGroup = true;
                    break;

                case '|':
                    $lastWasAtom = false;
                    $afterGroup = false;
                    break;

                case '*':
                case '+':
                case '?':
                case '{':
                    if ($afterGroup) {
                        return 'groups must not be repeated; repeat single characters or classes instead';
                    }
                    if (!$lastWasAtom) {
                        return 'a quantifier must follow a single character or class';
                    }
                    if ('{' === $c) {
                        $end = self::boundedQuantifierEnd($chars, $i);
                        if (null === $end) {
                            return 'use {n}, {n,} or {n,m} with numbers';
                        }
                        $i = $end;
                    }
                    if (\in_array($chars[$i + 1] ?? null, ['?', '+'], true)) {
                        return 'lazy and possessive quantifiers are not allowed';
                    }
                    $lastWasAtom = false;
                    break;

                case '^':
                case '$':
                    return 'anchors are not allowed; patterns are matched as whole words anyway';

                case ']':
                case '}':
                    return \sprintf('unexpected "%s"; escape it as \\%s', $c, $c);

                default:
                    $lastWasAtom = true; // literal or "."
                    $afterGroup = false;
            }
        }

        return 0 === $depth ? null : 'unbalanced parenthesis';
    }

    /**
     * @param list<string> $chars
     *
     * @return int|null index of the closing "]"
     */
    private static function classEnd(array $chars, int $start): ?int
    {
        $i = $start + 1;
        if ('^' === ($chars[$i] ?? null)) {
            ++$i;
        }
        for ($first = true; $i < \count($chars); ++$i, $first = false) {
            $c = $chars[$i];
            if (']' === $c && !$first) {
                return $i;
            }
            if ('[' === $c) {
                return null; // no nested classes or POSIX classes
            }
            if ('\\' === $c) {
                $next = $chars[$i + 1] ?? null;
                if (null === $next || (!str_contains(self::CLASS_SHORTHANDS, $next) && !str_contains(self::ESCAPABLE, $next))) {
                    return null;
                }
                ++$i;
            }
        }

        return null;
    }

    /**
     * @param list<string> $chars
     *
     * @return int|null index of the closing "}"
     */
    private static function boundedQuantifierEnd(array $chars, int $start): ?int
    {
        $body = '';
        $i = $start + 1;
        for (; $i < \count($chars) && '}' !== $chars[$i]; ++$i) {
            $body .= $chars[$i];
        }
        if (($chars[$i] ?? null) !== '}' || 1 !== preg_match('/^\d{1,3}(?:,\d{0,3})?$/', $body)) {
            return null;
        }

        return $i;
    }
}
