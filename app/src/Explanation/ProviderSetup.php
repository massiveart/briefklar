<?php

declare(strict_types=1);

namespace App\Explanation;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Tells whether the platform chosen with AI_PLATFORM can be used at all, so a fresh setup without
 * a provider key shows what to configure instead of a generic "try again later".
 */
final readonly class ProviderSetup
{
    private const array KEYS = [
        'huggingface' => 'HUGGINGFACE_API_KEY',
        'anthropic' => 'ANTHROPIC_API_KEY',
    ];

    public function __construct(
        #[Autowire('%env(AI_PLATFORM)%')]
        private string $platform,
        #[Autowire('%env(HUGGINGFACE_API_KEY)%')]
        private string $huggingFaceKey,
        #[Autowire('%env(ANTHROPIC_API_KEY)%')]
        private string $anthropicKey,
    ) {
    }

    /**
     * Name of the env variable that has to be set for the chosen platform, or null if nothing is missing.
     */
    public function missingKey(): ?string
    {
        $key = match ($this->platform) {
            'huggingface' => $this->huggingFaceKey,
            'anthropic' => $this->anthropicKey,
            default => null,
        };

        return null !== $key && '' === trim($key) ? self::KEYS[$this->platform] : null;
    }
}
