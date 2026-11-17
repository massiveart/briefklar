<?php

declare(strict_types=1);

namespace App\Explanation;

use App\Extraction\ExtractedText;
use Briefklar\PiiShield\Exception\ExceptionInterface as PiiShieldException;
use Briefklar\PiiShield\PiiShieldInterface;

/**
 * Explains a letter and keeps the placeholders in the answer, for callers that must not see the
 * personal data either, such as an MCP client's model (explain_letter_file, issue #64).
 *
 * The letter is redacted here first and then goes through the same shielded agent as in the web app.
 * pii-shield finds nothing left to redact and has nothing of its own to restore, so the answer comes
 * back with our placeholders. Every model call still passes the shield; there is no unshielded agent.
 */
final readonly class PlaceholderExplainer
{
    public function __construct(
        private LetterExplainer $explainer,
        private PiiShieldInterface $shield,
    ) {
    }

    /**
     * @throws ExplanationFailedException if redaction or the explanation fails, or an original value would come back
     */
    public function explain(
        ExtractedText $letter,
        ExplanationStyle $style = ExplanationStyle::Standard,
        ExplanationLanguage $language = ExplanationLanguage::German,
    ): ExplainedLetter {
        try {
            $redaction = $this->shield->redact($letter->text);
        } catch (PiiShieldException $e) {
            throw new ExplanationFailedException(\sprintf('Redaction failed (%s).', $e::class), previous: $e);
        }

        $explained = $this->explainer->explain(new ExtractedText($redaction->redactedText, $letter->method), $style, $language);

        // Safety net: if a second redaction pass ever assigned a placeholder of its own, its restore
        // could put an original value into the answer. Then nothing is returned.
        $answer = json_encode($explained->explanation, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        foreach ($redaction->vault as $original) {
            if ('' !== $original && str_contains($answer, $original)) {
                throw new ExplanationFailedException('The answer contained a redacted value; it was not returned.');
            }
        }

        return new ExplainedLetter(
            $explained->explanation,
            $explained->deadlineStatedInLetter,
            $explained->extractionMethod,
            $explained->style,
            $explained->language,
            // Our own placeholders are expected here; only the others were made up by the model.
            array_values(array_diff($explained->inventedPlaceholders, array_keys($redaction->vault))),
            $explained->inventedDates,
        );
    }
}
