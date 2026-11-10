<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Until task A ships, the app runs with NullPiiShield: text passes through unchanged.
 * When the alias in config/services.yaml points to the real shield, this test must be updated.
 */
final class PiiShieldWiringTest extends KernelTestCase
{
    public function testInterfaceIsWiredToPassThroughShield(): void
    {
        self::bootKernel();

        $shield = self::getContainer()->get('test.pii_shield');
        $result = $shield->redact('Herr Max Mustermann, IBAN AT61 1904 3002 3457 3201');

        self::assertSame('Herr Max Mustermann, IBAN AT61 1904 3002 3457 3201', $result->redactedText);
        self::assertSame([], $result->vault);
    }
}
