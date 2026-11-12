<?php

declare(strict_types=1);

namespace App\Explanation\Evaluation;

use App\Explanation\ExplainedLetter;
use App\Explanation\ExplanationLanguage;
use App\Explanation\ExplanationStyle;

/**
 * Checks an explanation against the rules of the system prompts, so prompt and model changes
 * can be measured (bin/console app:evaluate-explanations) instead of judged by eye.
 *
 * Each rule is true (kept), false (broken) or null (does not apply to this style or language).
 */
final readonly class ExplanationRules
{
    public const string DEADLINE_VERBATIM = 'deadline verbatim';
    public const string AMOUNT_VERBATIM = 'amount verbatim';
    public const string REFERENCES_VERBATIM = 'references verbatim';
    public const string SENDER_FROM_LETTER = 'sender from letter';
    public const string PLACEHOLDERS_RESTORED = 'placeholders restored';
    public const string INFORMAL_ADDRESS = 'du, not Sie';
    public const string MIDDLE_DOTS = 'middle dots';
    public const string ACTIONS_CONSISTENT = 'actions consistent';

    /** Formal address in German; the prompts ask for "du". */
    private const string FORMAL_ADDRESS = '/\b(?:Sie|Ihnen|Ihr|Ihre|Ihren|Ihrem|Ihrer|Ihres)\b/u';

    /**
     * @return array<string, bool|null> rule name => result
     */
    public function check(ExplainedLetter $result, string $letter): array
    {
        $e = $result->explanation;
        $letter = self::normalize($letter);
        $prose = [$e->subject, $e->summary, ...$e->actions, ...$e->questionsToAsk];
        $german = ExplanationLanguage::German === $result->language;

        return [
            self::DEADLINE_VERBATIM => $result->deadlineStatedInLetter,
            self::AMOUNT_VERBATIM => null === $e->amount || str_contains($letter, self::normalize($e->amount)),
            self::REFERENCES_VERBATIM => array_all($e->legalReferences, static fn (string $r): bool => str_contains($letter, self::normalize($r))),
            self::SENDER_FROM_LETTER => str_contains($letter, self::normalize($e->sender)),
            self::PLACEHOLDERS_RESTORED => [] === $result->inventedPlaceholders
                && array_all([$e->sender, $e->amount ?? '', $e->deadline ?? '', ...$prose, ...$e->legalReferences], static fn (string $t): bool => !str_contains($t, '⟦')),
            self::INFORMAL_ADDRESS => $german ? array_all($prose, static fn (string $t): bool => 1 !== preg_match(self::FORMAL_ADDRESS, $t)) : null,
            // actionRequired must match the steps, and an amount to pay always needs a step.
            self::ACTIONS_CONSISTENT => $e->actionRequired === ([] !== $e->actions) && (null === $e->amount || [] !== $e->actions),
            self::MIDDLE_DOTS => $german && ExplanationStyle::EasyLanguage === $result->style
                ? array_any($prose, static fn (string $t): bool => str_contains($t, '·'))
                : null,
        ];
    }

    private static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
