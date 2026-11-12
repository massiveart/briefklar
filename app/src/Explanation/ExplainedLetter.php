<?php

declare(strict_types=1);

namespace App\Explanation;

use App\Extraction\ExtractionMethod;

/**
 * Explanation plus what the app knows about its reliability. Shown on the result page, never stored.
 */
final readonly class ExplainedLetter
{
    /**
     * @param list<string> $inventedPlaceholders placeholders in the answer that were not in the letter (pii-shield could not restore them)
     */
    public function __construct(
        public LetterExplanation $explanation,
        public bool $deadlineStatedInLetter,
        public ExtractionMethod $extractionMethod,
        public ExplanationStyle $style,
        public ExplanationLanguage $language,
        public array $inventedPlaceholders = [],
    ) {
    }

    public function actionAdvice(): ActionAdvice
    {
        return ActionAdvice::of($this->explanation);
    }
}
