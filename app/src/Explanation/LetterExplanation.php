<?php

declare(strict_types=1);

namespace App\Explanation;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

/**
 * Structured answer of the explanation agent.
 *
 * Shared contract (docs/tasks/B-briefklar-app.md): change only together with the pii-shield owner.
 * Descriptions end up in the JSON schema sent to the model.
 */
final readonly class LetterExplanation
{
    /**
     * @param list<string> $actions
     * @param list<string> $legalReferences
     * @param list<string> $questionsToAsk
     */
    public function __construct(
        #[Schema(description: 'Authority or company that sent the letter, as named in the letter.')]
        public string $sender,
        #[Schema(description: 'What the letter is about, in one sentence.')]
        public string $subject,
        #[Schema(description: 'Plain-language explanation, at most 5 sentences.')]
        public string $summary,
        #[Schema(description: 'True if the recipient has to do something (pay, reply, appeal, submit documents).')]
        public bool $actionRequired,
        #[Schema(description: 'Concrete steps for the recipient, in order. Empty if nothing has to be done.')]
        public array $actions,
        #[Schema(description: 'Deadline exactly as stated in the letter, never computed or guessed. If a calendar date is stated: ISO date (YYYY-MM-DD), then " – " and the verbatim sentence it came from. If only a period is stated (e.g. "binnen zwei Wochen ab Zustellung"): that period verbatim, then " – " and the sentence. Null if the letter states no deadline.')]
        public ?string $deadline,
        #[Schema(description: 'Amount to pay including currency, as stated in the letter. Null if none.')]
        public ?string $amount,
        #[Schema(description: 'Legal provisions cited in the letter, verbatim, e.g. "§ 49 VStG".')]
        public array $legalReferences,
        #[Schema(description: 'Questions the recipient could ask the sender if something is unclear.')]
        public array $questionsToAsk,
    ) {
    }
}
