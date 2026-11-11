<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Tests\Demo;

use Briefklar\PiiShield\Demo\DemoServices;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The demo log may contain model answers with personal data (PII_DEMO_DEBUG=1):
 * owner-only file, one file per day, deleted after a week.
 */
final class DemoLogTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/pii-demo-log-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testLogFileIsReadableByTheOwnerOnly(): void
    {
        (new DemoServices($this->dir))->log(['action' => 'analyze']);

        $files = glob($this->dir.'/log/demo-*.log');
        self::assertIsArray($files);
        self::assertCount(1, $files);
        self::assertSame(0600, fileperms($files[0]) & 0777);
        self::assertStringContainsString('"action":"analyze"', (string) file_get_contents($files[0]));
    }

    public function testLogsOlderThanAWeekAreDeleted(): void
    {
        mkdir($this->dir.'/log', 0700, true);
        $old = $this->dir.'/log/demo-2026-01-01.log';
        $recent = $this->dir.'/log/demo-recent.log';
        $legacy = $this->dir.'/log/demo.log';
        foreach ([$old, $recent, $legacy] as $file) {
            touch($file);
        }
        touch($old, time() - 8 * 86_400);
        touch($legacy, time() - 8 * 86_400);

        (new DemoServices($this->dir))->log(['action' => 'analyze']);

        self::assertFileDoesNotExist($old);
        self::assertFileDoesNotExist($legacy);
        self::assertFileExists($recent);
    }
}
