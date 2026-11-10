<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Tests\Bridge\SymfonyAi\Fixtures;

/**
 * Structured output DTO as an agent would return it (readonly, nested).
 */
final readonly class Explanation
{
    /**
     * @param list<string> $actions
     */
    public function __construct(
        public string $summary,
        public array $actions,
        public Payment $payment,
        public int $amountInCents,
    ) {
    }
}
