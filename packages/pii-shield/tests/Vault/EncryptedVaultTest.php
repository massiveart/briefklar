<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Tests\Vault;

use Briefklar\PiiShield\Exception\VaultException;
use Briefklar\PiiShield\Vault\EncryptedVault;
use Briefklar\PiiShield\Vault\Storage\Psr6CacheStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class EncryptedVaultTest extends TestCase
{
    private const array MAPPING = [
        '⟦PERSON_1⟧' => 'Maria Musterfrau',
        '⟦SVNR_1⟧' => '4568 150392',
    ];

    private ArrayAdapter $pool;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock();
        $this->pool = new ArrayAdapter(clock: $this->clock);
    }

    public function testRoundTrip(): void
    {
        $vault = $this->vault();

        $vault->store('id-1', self::MAPPING, 60);

        self::assertSame(self::MAPPING, $vault->fetch('id-1'));
    }

    public function testStorageNeverSeesClearText(): void
    {
        $this->vault()->store('id-1', self::MAPPING, 60);

        $stored = implode('', array_map(serialize(...), $this->pool->getValues()));
        self::assertNotSame('', $stored);
        foreach (self::MAPPING as $placeholder => $original) {
            self::assertStringNotContainsString($original, $stored);
            self::assertStringNotContainsString($placeholder, $stored);
        }
    }

    public function testUnknownIdReturnsNull(): void
    {
        self::assertNull($this->vault()->fetch('unknown'));
    }

    public function testDeleteRemovesMapping(): void
    {
        $vault = $this->vault();
        $vault->store('id-1', self::MAPPING, 60);

        $vault->delete('id-1');

        self::assertNull($vault->fetch('id-1'));
    }

    public function testMappingExpiresAfterTtl(): void
    {
        $vault = $this->vault();
        $vault->store('id-1', self::MAPPING, 60);

        $this->clock->sleep(61);

        self::assertNull($vault->fetch('id-1'));
    }

    public function testTtlMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->vault()->store('id-1', self::MAPPING, 0);
    }

    public function testWrongKeyCannotDecrypt(): void
    {
        $this->vault()->store('id-1', self::MAPPING, 60);

        $this->expectException(VaultException::class);

        $this->vault(EncryptedVault::generateKey())->fetch('id-1');
    }

    public function testCiphertextIsBoundToItsId(): void
    {
        $storage = new Psr6CacheStorage($this->pool);
        $vault = new EncryptedVault($storage, self::key());
        $vault->store('id-1', self::MAPPING, 60);

        // An attacker with storage access copies the ciphertext to another id.
        $ciphertext = $storage->get('id-1');
        self::assertNotNull($ciphertext);
        $storage->put('id-2', $ciphertext, 60);

        $this->expectException(VaultException::class);

        $vault->fetch('id-2');
    }

    public function testKeyMustHaveTheRightLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EncryptedVault(new Psr6CacheStorage($this->pool), 'too-short');
    }

    public function testGeneratedKeysAreRandom(): void
    {
        self::assertNotSame(EncryptedVault::generateKey(), EncryptedVault::generateKey());
    }

    private function vault(?string $key = null): EncryptedVault
    {
        return new EncryptedVault(new Psr6CacheStorage($this->pool), $key ?? self::key());
    }

    private static function key(): string
    {
        return str_repeat("\x01", \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    }
}
