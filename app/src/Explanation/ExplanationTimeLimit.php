<?php

declare(strict_types=1);

namespace App\Explanation;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Raises PHP's time limit for a request that explains a letter (web upload, MCP over HTTP).
 *
 * PHP's default of 30 s would cut off a local model mid-answer (30-60 s per letter on a laptop).
 * The HTTP client timeout (EXPLANATION_TIMEOUT, framework.yaml) ends a hanging model call first,
 * with a proper error. Up to two model calls: detection (PII_MODEL_DETECTION) and explanation,
 * plus headroom for OCR of a multi-page scan. On the command line PHP has no limit anyway.
 */
final readonly class ExplanationTimeLimit
{
    private const int EXTRACTION_HEADROOM_SECONDS = 60;

    public function __construct(
        #[Autowire('%env(int:EXPLANATION_TIMEOUT)%')]
        private int $explanationTimeout,
    ) {
    }

    public function seconds(): int
    {
        return 2 * $this->explanationTimeout + self::EXTRACTION_HEADROOM_SECONDS;
    }

    public function apply(): void
    {
        set_time_limit($this->seconds());
    }
}
