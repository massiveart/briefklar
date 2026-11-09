<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Tests\Vault;

use Briefklar\PiiShield\Vault\Storage\Psr6CacheStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class Psr6CacheStorageTest extends TestCase
{
    public function testPutGetRemove(): void
    {
        $storage = new Psr6CacheStorage(new ArrayAdapter());

        $storage->put('id-1', "\x00binary\xff", 60);
        self::assertSame("\x00binary\xff", $storage->get('id-1'));

        $storage->remove('id-1');
        self::assertNull($storage->get('id-1'));
    }

    public function testExpiresAfterTtl(): void
    {
        $clock = new MockClock();
        $storage = new Psr6CacheStorage(new ArrayAdapter(clock: $clock));
        $storage->put('id-1', 'payload', 60);

        $clock->sleep(61);

        self::assertNull($storage->get('id-1'));
    }

    public function testIdsWithReservedCharactersAreSafe(): void
    {
        // PSR-6 forbids {}()/\@: in keys; ids come from outside (e.g. MCP clients).
        $storage = new Psr6CacheStorage(new ArrayAdapter());

        $storage->put('a/b:c@d', 'payload', 60);

        self::assertSame('payload', $storage->get('a/b:c@d'));
        self::assertNull($storage->get('a_b_c_d'));
    }
}
