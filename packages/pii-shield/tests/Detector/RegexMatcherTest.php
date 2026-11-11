<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Tests\Detector;

use Briefklar\PiiShield\Detector\RegexMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RegexMatcherTest extends TestCase
{
    private const string TEXT = "ab\nÄrger x@y.at\n\nz";

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function lines(): iterable
    {
        yield 'start of text' => [0, 'ab'];
        yield 'inside the first line' => [1, 'ab'];
        yield 'on the first newline' => [2, 'ab'];
        yield 'start of a line after a newline' => [3, 'Ärger x@y.at'];
        yield 'after a multibyte character' => [5, 'Ärger x@y.at'];
        yield 'last character of a line' => [15, 'Ärger x@y.at'];
        yield 'empty line' => [17, ''];
        yield 'last line' => [18, 'z'];
    }

    #[DataProvider('lines')]
    public function testLineAroundAByteOffset(int $byteOffset, string $line): void
    {
        self::assertSame($line, RegexMatcher::lineAround(self::TEXT, $byteOffset));
    }

    public function testOffsetsAreCodePointsAndBytes(): void
    {
        $matches = RegexMatcher::matchAll('/x@y\.at|z/u', self::TEXT);

        self::assertSame([
            ['value' => 'x@y.at', 'offset' => 9, 'byteOffset' => 10],
            ['value' => 'z', 'offset' => 17, 'byteOffset' => 18],
        ], $matches);
    }

    public function testGroupOffsetsInLaterMatches(): void
    {
        $matches = RegexMatcher::matchAll('/Ä(?<w>\w+)|x(?<w2>@)/u', 'Äb Äc x@', 'w');

        self::assertSame([['value' => 'b', 'offset' => 1, 'byteOffset' => 2], ['value' => 'c', 'offset' => 4, 'byteOffset' => 6]], $matches);
    }
}
