<?php

declare(strict_types=1);

namespace App\Explanation;

use App\Extraction\ExtractionMethod;

/**
 * Explanation plus what the app knows about its reliability. Shown on the result page, never stored.
 */
final readonly class ExplainedLetter
{
    public function __construct(
        public LetterExplanation $explanation,
        public bool $deadlineStatedInLetter,
        public ExtractionMethod $extractionMethod,
        public ExplanationStyle $style,
        public ExplanationLanguage $language,
    ) {
    }
}
