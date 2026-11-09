<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Tests\Vault;

use Briefklar\PiiShield\Vault\InMemoryVault;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class InMemoryVaultTest extends TestCase
{
    private const array MAPPING = ['⟦PERSON_1⟧' => 'Maria Musterfrau'];

    public function testFetchesStoredMapping(): void
    {
        $vault = new InMemoryVault();

        $vault->store('id-1', self::MAPPING, 60);

        self::assertSame(self::MAPPING, $vault->fetch('id-1'));
    }

    public function testUnknownIdReturnsNull(): void
    {
        self::assertNull((new InMemoryVault())->fetch('unknown'));
    }

    public function testDeleteRemovesMapping(): void
    {
        $vault = new InMemoryVault();
        $vault->store('id-1', self::MAPPING, 60);

        $vault->delete('id-1');

        self::assertNull($vault->fetch('id-1'));
    }

    public function testMappingExpiresAfterTtl(): void
    {
        $clock = new MockClock();
        $vault = new InMemoryVault($clock);
        $vault->store('id-1', self::MAPPING, 60);

        $clock->sleep(59);
        self::assertSame(self::MAPPING, $vault->fetch('id-1'));

        $clock->sleep(1);
        self::assertNull($vault->fetch('id-1'));
    }

    public function testTtlMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new InMemoryVault())->store('id-1', self::MAPPING, 0);
    }
}
