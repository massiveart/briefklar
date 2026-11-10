<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Tests;

use Briefklar\PiiShield\Exception\VaultException;
use Briefklar\PiiShield\PiiShield;
use Briefklar\PiiShield\Vault\EncryptedVault;
use Briefklar\PiiShield\Vault\InMemoryVault;
use Briefklar\PiiShield\Vault\Storage\Psr6CacheStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class PiiShieldVaultTest extends TestCase
{
    private const string LETTER = "Frau\nMaria Musterfrau\n\nSVNR 4568 150392";

    public function testWithoutVaultNoIdIsAssigned(): void
    {
        $result = PiiShield::withDefaultDetectors()->redact(self::LETTER);

        self::assertNull($result->vaultId);
    }

    public function testWithoutVaultRestoreFromVaultIsALogicError(): void
    {
        $this->expectException(\LogicException::class);

        PiiShield::withDefaultDetectors()->restoreFromVault('⟦PERSON_1⟧', 'some-id');
    }

    public function testRedactStoresTheMappingUnderARandomId(): void
    {
        $vault = new InMemoryVault();
        $shield = PiiShield::withDefaultDetectors($vault);

        $first = $shield->redact(self::LETTER);
        $second = $shield->redact(self::LETTER);

        self::assertNotNull($first->vaultId);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $first->vaultId);
        self::assertNotSame($first->vaultId, $second->vaultId);
        self::assertSame($first->vault, $vault->fetch($first->vaultId));
    }

    public function testRestoreFromVaultInALaterRequest(): void
    {
        $pool = new ArrayAdapter();
        $key = EncryptedVault::generateKey();

        // Request 1: mask
        $vaultId = PiiShield::withDefaultDetectors(new EncryptedVault(new Psr6CacheStorage($pool), $key))
            ->redact(self::LETTER)->vaultId;
        self::assertNotNull($vaultId);

        // Request 2: new shield instance, same storage and key
        $restored = PiiShield::withDefaultDetectors(new EncryptedVault(new Psr6CacheStorage($pool), $key))
            ->restoreFromVault('⟦PERSON_1⟧ hat die SVNR ⟦SVNR_1⟧.', $vaultId);

        self::assertSame('Maria Musterfrau hat die SVNR 4568 150392.', $restored->text);
        self::assertTrue($restored->isClean());
    }

    public function testTextWithoutPiiIsStoredToo(): void
    {
        $shield = PiiShield::withDefaultDetectors(new InMemoryVault());

        $result = $shield->redact('Kein Personenbezug.');

        self::assertNotNull($result->vaultId);
        self::assertSame('Text', $shield->restoreFromVault('Text', $result->vaultId)->text);
    }

    public function testForgetDeletesTheMapping(): void
    {
        $shield = PiiShield::withDefaultDetectors(new InMemoryVault());
        $vaultId = $shield->redact(self::LETTER)->vaultId;
        self::assertNotNull($vaultId);

        $shield->forget($vaultId);

        $this->expectException(VaultException::class);
        $shield->restoreFromVault('⟦PERSON_1⟧', $vaultId);
    }

    public function testMappingExpiresAfterConfiguredTtl(): void
    {
        $clock = new MockClock();
        $shield = PiiShield::withDefaultDetectors(new InMemoryVault($clock), vaultTtl: 300);
        $vaultId = $shield->redact(self::LETTER)->vaultId;
        self::assertNotNull($vaultId);

        $clock->sleep(301);

        $this->expectException(VaultException::class);
        $shield->restoreFromVault('⟦PERSON_1⟧', $vaultId);
    }

    public function testInvalidTtlIsRejectedEarly(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PiiShield::withDefaultDetectors(new InMemoryVault(), vaultTtl: 0);
    }

    public function testDebugOutputDoesNotLeakTheVault(): void
    {
        $result = PiiShield::withDefaultDetectors(new InMemoryVault())->redact(self::LETTER);

        $dump = print_r($result, true);

        self::assertStringNotContainsString('Maria Musterfrau', $dump);
        self::assertStringContainsString((string) $result->vaultId, $dump);
    }
}
