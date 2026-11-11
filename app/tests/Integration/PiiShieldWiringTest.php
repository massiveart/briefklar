<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The app runs with the real pii-shield from PiiShieldBundle (config/packages/pii_shield.yaml).
 * That it actually sits in front of the provider is covered end to end by PiiProtectionTest.
 */
final class PiiShieldWiringTest extends KernelTestCase
{
    public function testInterfaceIsWiredToTheRealShield(): void
    {
        self::bootKernel();

        // PHPStan resolves the service to PiiShield; the redaction proves it is not NullPiiShield.
        $result = self::getContainer()->get('test.pii_shield')->redact('Herr Max Mustermann, IBAN AT61 1904 3002 3457 3201');
        self::assertSame('Herr ⟦PERSON_1⟧, IBAN ⟦IBAN_1⟧', $result->redactedText);
    }
}
