<?php

declare(strict_types=1);

namespace App\Tests\Unit\Explanation;

use App\Explanation\ProviderSetup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProviderSetupTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, ?string}>
     */
    public static function setups(): iterable
    {
        yield 'hugging face with key' => ['huggingface', 'hf-key', '', null];
        yield 'hugging face without key' => ['huggingface', '', 'anthropic-key', 'HUGGINGFACE_API_KEY'];
        yield 'hugging face with blank key' => ['huggingface', '  ', '', 'HUGGINGFACE_API_KEY'];
        yield 'anthropic with key' => ['anthropic', '', 'anthropic-key', null];
        yield 'anthropic without key' => ['anthropic', 'hf-key', '', 'ANTHROPIC_API_KEY'];
        yield 'ollama needs no key' => ['ollama', '', '', null];
    }

    #[DataProvider('setups')]
    public function testNamesTheMissingKey(string $platform, string $huggingFaceKey, string $anthropicKey, ?string $expected): void
    {
        self::assertSame($expected, (new ProviderSetup($platform, $huggingFaceKey, $anthropicKey))->missingKey());
    }
}
