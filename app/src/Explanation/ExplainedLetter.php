<?php

declare(strict_types=1);

namespace App\Explanation;

use App\Extraction\ExtractionMethod;
use App\Payment\PaymentDetails;

/**
 * Explanation plus what the app knows about its reliability. Shown on the result page, never stored.
 */
final readonly class ExplainedLetter
{
    /**
     * @param list<string>        $inventedPlaceholders placeholders in the answer that were not in the letter (pii-shield could not restore them)
     * @param PaymentDetails|null $payment              the transfer the letter asks for, read from the letter text (PAYMENT_QR), null if off or uncertain
     */
    public function __construct(
        public LetterExplanation $explanation,
        public bool $deadlineStatedInLetter,
        public ExtractionMethod $extractionMethod,
        public ExplanationStyle $style,
        public ExplanationLanguage $language,
        public array $inventedPlaceholders = [],
        public ?PaymentDetails $payment = null,
    ) {
    }

    public function actionAdvice(): ActionAdvice
    {
        return ActionAdvice::of($this->explanation);
    }
}
