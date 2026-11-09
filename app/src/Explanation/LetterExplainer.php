<?php

declare(strict_types=1);

namespace App\Explanation;

use App\Extraction\ExtractedText;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Exception\ExceptionInterface as AgentException;
use Symfony\AI\Platform\Exception\ExceptionInterface as PlatformException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;

/**
 * Asks the explanation agent (config/packages/ai.yaml) for a structured explanation.
 *
 * Redaction is not done here: pii-shield hooks into the agent as input/output processor,
 * so this class only ever sees the original text and the restored answer.
 */
final readonly class LetterExplainer
{
    public function __construct(
        #[Autowire(service: 'ai.agent.letter_explainer')]
        private AgentInterface $agent,
        private DeadlineCheck $deadlineCheck,
    ) {
    }

    /**
     * @throws ExplanationFailedException if the provider fails or returns no valid explanation
     */
    public function explain(ExtractedText $letter): ExplainedLetter
    {
        $messages = new MessageBag(Message::ofUser("<letter>\n".$letter->text."\n</letter>"));

        try {
            $explanation = $this->agent
                ->call($messages, ['response_format' => LetterExplanation::class])
                ->asObject(LetterExplanation::class);
        } catch (AgentException|PlatformException|HttpException $e) {
            // The message of provider errors may echo the request: keep it out of logs and UI.
            throw new ExplanationFailedException(\sprintf('The explanation agent failed (%s).', $e::class), previous: $e);
        }

        $deadlineStated = null === $explanation->deadline
            || $this->deadlineCheck->isStatedInLetter($explanation->deadline, $letter->text);

        return new ExplainedLetter($explanation, $deadlineStated, $letter->method);
    }
}
