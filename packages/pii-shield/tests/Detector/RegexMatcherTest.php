<?php

declare(strict_types=1);

namespace Clarifile\PiiShield\Tests\Detector;

use Clarifile\PiiShield\Detector\RegexMatcher;
use PHPUnit\Framework\TestCase;

final class RegexMatcherTest extends TestCase
{
    private const string TEXT = "ab\nÄrger x@y.at\n\nz";

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
