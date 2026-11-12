<?php

declare(strict_types=1);

namespace App\Explanation;

use App\Extraction\ExtractedText;
use App\Payment\PaymentDetailsExtractor;
use Briefklar\PiiShield\Bridge\SymfonyAi\PiiShieldProcessor;
use Briefklar\PiiShield\Exception\ExceptionInterface as PiiShieldException;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Exception\ExceptionInterface as AgentException;
use Symfony\AI\Platform\Exception\ExceptionInterface as PlatformException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;

/**
 * Asks the explanation agent (config/packages/ai.yaml) for a structured explanation,
 * in the user's language, in standard or easy-to-read wording.
 *
 * Redaction is not done here: pii-shield hooks into the agent as input/output processor
 * (config/packages/pii_shield.yaml), so this class only ever sees the original text and the
 * restored answer. If redaction fails, nothing is sent and the explanation fails.
 */
final readonly class LetterExplainer
{
    public function __construct(
        #[Autowire(service: 'ai.agent.letter_explainer')]
        private AgentInterface $standardAgent,
        #[Autowire(service: 'ai.agent.letter_explainer_easy')]
        private AgentInterface $easyLanguageAgent,
        private DeadlineCheck $deadlineCheck,
        private ProseDateCheck $proseDateCheck,
        private PaymentDetailsExtractor $paymentExtractor,
        #[Autowire('%env(bool:PAYMENT_QR)%')]
        private bool $paymentQr,
    ) {
    }

    /**
     * @throws ExplanationFailedException if redaction or the provider fails, or no valid explanation comes back
     */
    public function explain(
        ExtractedText $letter,
        ExplanationStyle $style = ExplanationStyle::Standard,
        ExplanationLanguage $language = ExplanationLanguage::German,
    ): ExplainedLetter {
        // The answer language goes after the letter, outside the part the model must treat as data.
        $messages = new MessageBag(Message::ofUser(\sprintf(
            "<letter>\n%s\n</letter>\n\nAnswer in %s.",
            $letter->text,
            $language->englishName(),
        )));

        $agent = match ($style) {
            ExplanationStyle::Standard => $this->standardAgent,
            ExplanationStyle::EasyLanguage => $this->easyLanguageAgent,
        };

        try {
            $execution = $agent->call($messages, ['response_format' => LetterExplanation::class]);
            $explanation = $execution->asObject(LetterExplanation::class);
            $shieldReport = $execution->getResult()->getMetadata()->get(PiiShieldProcessor::METADATA_KEY);
        } catch (PiiShieldException|AgentException|PlatformException|HttpException $e) {
            // The message of provider errors may echo the request: keep it out of logs and UI.
            throw new ExplanationFailedException(\sprintf('The explanation agent failed (%s).', $e::class), previous: $e);
        }

        $deadlineStated = null === $explanation->deadline
            || $this->deadlineCheck->isStatedInLetter($explanation->deadline, $letter->text);

        // Placeholders the model made up cannot be restored and stay visible in the answer.
        $inventedPlaceholders = \is_array($shieldReport) && \is_array($shieldReport['unknown_placeholders'] ?? null)
            ? array_values(array_filter($shieldReport['unknown_placeholders'], \is_string(...)))
            : [];

        // From the letter text only, never from the answer alone (see PaymentDetailsExtractor).
        $payment = $this->paymentQr ? $this->paymentExtractor->extract($letter->text, $explanation) : null;

        return new ExplainedLetter(
            $explanation,
            $deadlineStated,
            $letter->method,
            $style,
            $language,
            $inventedPlaceholders,
            $this->proseDateCheck->datesNotInLetter($explanation, $letter->text),
            $payment,
        );
    }
}
