<?php

declare(strict_types=1);

namespace App\Explanation;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Raises PHP's time limit for a request that explains a letter (web upload, MCP over HTTP).
 *
 * PHP's default of 30 s would cut off a local model mid-answer (30-60 s per letter on a laptop).
 * The HTTP client timeout (EXPLANATION_TIMEOUT, framework.yaml) ends a hanging model call first,
 * with a proper error. So the limit is one EXPLANATION_TIMEOUT per model call: the explanation, plus
 * one detection call per redaction pass when PII_MODEL_DETECTION is on, plus headroom for OCR of a
 * multi-page scan. On the command line PHP has no limit anyway.
 */
final readonly class ExplanationTimeLimit
{
    private const int EXTRACTION_HEADROOM_SECONDS = 60;

    public function __construct(
        #[Autowire('%env(int:EXPLANATION_TIMEOUT)%')]
        private int $explanationTimeout,
        #[Autowire('%env(bool:PII_MODEL_DETECTION)%')]
        private bool $modelDetection,
    ) {
    }

    /**
     * @param positive-int $redactionPasses how often the letter is redacted before the explanation
     */
    public function seconds(int $redactionPasses = 1): int
    {
        $modelCalls = 1 + ($this->modelDetection ? $redactionPasses : 0);

        return $modelCalls * $this->explanationTimeout + self::EXTRACTION_HEADROOM_SECONDS;
    }

    /**
     * @param positive-int $redactionPasses how often the letter is redacted before the explanation
     */
    public function apply(int $redactionPasses = 1): void
    {
        set_time_limit($this->seconds($redactionPasses));
    }
}
