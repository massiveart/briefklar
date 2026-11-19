<?php

declare(strict_types=1);

namespace App\Tests\Integration\Mcp;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * mcp/sdk logs the raw request ("Received message payload.") and the tool arguments at debug on the
 * channel "mcp", and validation errors with values from the answer. For explain_letter and redact_text
 * that is the letter itself, so the channel must reach no log handler in any environment (issue #84).
 */
final class McpLoggingTest extends KernelTestCase
{
    public function testMcpChannelReachesNoLogHandlerInTheTestContainer(): void
    {
        $mcp = self::getContainer()->get('monolog.logger.mcp');
        self::assertSame([], $mcp->getHandlers(), 'The "mcp" channel must not be logged.');

        // The app's own channel still logs, so errors of the letter tools (without letter text) are kept.
        self::assertNotSame([], self::getContainer()->get('monolog.logger')->getHandlers());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function environments(): iterable
    {
        yield 'dev' => ['dev'];
        yield 'test' => ['test'];
        yield 'prod' => ['prod'];
    }

    /**
     * Dev and prod cannot be booted with the test container, so their configuration is checked as written:
     * every handler that receives records itself (not only as the target of another one) excludes "mcp".
     */
    #[DataProvider('environments')]
    public function testEveryHandlerExcludesTheMcpChannel(string $environment): void
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 3).'/config/packages/monolog.yaml');
        $handlers = $config['when@'.$environment]['monolog']['handlers'] ?? [];
        self::assertNotSame([], $handlers);

        $nested = array_filter(array_column($handlers, 'handler'), static fn (mixed $name): bool => \is_string($name));
        foreach ($handlers as $name => $handler) {
            if (\in_array($name, $nested, true)) {
                continue;
            }
            $channels = $handler['channels'] ?? null;
            self::assertIsArray($channels, \sprintf('Handler "%s" (%s) has no channel list, so it receives "mcp".', $name, $environment));
            $exclusive = [] !== $channels && str_starts_with((string) $channels[0], '!');
            self::assertTrue(
                $exclusive ? \in_array('!mcp', $channels, true) : !\in_array('mcp', $channels, true),
                \sprintf('Handler "%s" (%s) receives the "mcp" channel.', $name, $environment),
            );
        }
    }
}
