<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Tests;

use Briefklar\PiiShield\NullPiiShield;
use PHPUnit\Framework\TestCase;

final class NullPiiShieldTest extends TestCase
{
    public function testRoundTripIsIdentity(): void
    {
        $shield = new NullPiiShield();
        $text = 'Sehr geehrte Frau Muster, SVNR 1237 010180';

        $redaction = $shield->redact($text);

        self::assertSame($text, $redaction->redactedText);
        self::assertSame($text, $shield->restore($redaction->redactedText, $redaction)->text);
    }
}
