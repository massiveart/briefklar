<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Tests\Demo;

use Briefklar\PiiShield\Demo\DemoServices;
use PHPUnit\Framework\TestCase;

final class LocalPeerTest extends TestCase
{
    public function testReadsTheDefaultGatewayFromTheRouteTable(): void
    {
        $routes = "Iface\tDestination\tGateway \tFlags\tRefCnt\tUse\tMetric\tMask\t\tMTU\tWindow\tIRTT\n"
            ."eth0\t00000000\t010011AC\t0003\t0\t0\t0\t00000000\t0\t0\t0\n"
            ."eth0\t000011AC\t00000000\t0001\t0\t0\t0\t0000FFFF\t0\t0\t0\n";

        self::assertSame('172.17.0.1', DemoServices::defaultGateway($routes));
    }

    public function testNoDefaultRouteMeansNoGateway(): void
    {
        self::assertNull(DemoServices::defaultGateway("Iface\tDestination\tGateway\n"));
        self::assertNull(DemoServices::defaultGateway(''));
    }

    public function testLoopbackIsAlwaysLocal(): void
    {
        self::assertTrue(DemoServices::isLocalPeer('127.0.0.1'));
        self::assertTrue(DemoServices::isLocalPeer('::1'));
        self::assertFalse(DemoServices::isLocalPeer('172.17.0.1'));
        self::assertFalse(DemoServices::isLocalPeer(null));
    }
}
