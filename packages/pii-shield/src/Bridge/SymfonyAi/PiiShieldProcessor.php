<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Bridge\SymfonyAi;

use Briefklar\PiiShield\Exception\UnsupportedContentException;
use Briefklar\PiiShield\PiiShieldInterface;
use Briefklar\PiiShield\RedactionResult;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\InputProcessorInterface;
use Symfony\AI\Agent\Output;
use Symfony\AI\Agent\OutputProcessorInterface;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\MessageInterface;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\BaseResult;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\TextResult;

/**
 * Symfony AI agent input and output processor: the provider only ever sees placeholders,
 * the caller gets the answer with the original values.
 *
 * Register the same instance as input and output processor. The redaction is handed from input
 * to output via the processed MessageBag (WeakMap), never via options, which go to the provider.
 *
 * Input: all text of system, user, assistant and tool messages is redacted in one go, so the same
 * value gets the same placeholder across messages. Images, audio, video, documents and URLs in
 * user or tool messages are rejected (UnsupportedContentException). System prompt templates are
 * passed through (developer-authored; do not put personal data into template variables).
 *
 * Output: TextResult and ObjectResult (structured output, deeply) are restored. Placeholders the
 * model invented are left as they are and reported in result metadata under METADATA_KEY.
 * Streamed results are not restored yet.
 */
final class PiiShieldProcessor implements InputProcessorInterface, OutputProcessorInterface
{
    public const string METADATA_KEY = 'pii_shield';

    /** Joins all texts for one consistent redaction; contains no character any detector matches. */
    private const string SEPARATOR = "\n\u{2063}\u{2063}\u{2063}\n";

    /** @var \WeakMap<MessageBag, RedactionResult> */
    private \WeakMap $redactions;

    public function __construct(
        private readonly PiiShieldInterface $shield,
    ) {
        $this->redactions = new \WeakMap();
    }

    public function processInput(Input $input): void
    {
        $bag = $input->getMessageBag();

        $texts = [];
        foreach ($bag->getMessages() as $message) {
            foreach ($this->textsOf($message) as $text) {
                $texts[] = $text;
            }
        }
        if ([] === $texts) {
            return;
        }

        $redaction = $this->shield->redact(implode(self::SEPARATOR, $texts));
        $redactedTexts = explode(self::SEPARATOR, $redaction->redactedText);
        if (\count($redactedTexts) !== \count($texts)) {
            throw new \LogicException('Redaction changed the message structure.');
        }

        $messages = [];
        foreach ($bag->getMessages() as $message) {
            $messages[] = $this->withTexts($message, $redactedTexts);
        }
        $redactedBag = (new MessageBag(...$messages))->withId($bag->getId());

        $this->redactions[$redactedBag] = $redaction;
        $input->setMessageBag($redactedBag);
    }

    public function processOutput(Output $output): void
    {
        $redaction = $this->redactions[$output->getMessageBag()] ?? null;
        if (null === $redaction) {
            return;
        }

        $unknown = [];
        $restore = function (string $text) use ($redaction, &$unknown): string {
            $restored = $this->shield->restore($text, $redaction);
            array_push($unknown, ...$restored->unknownPlaceholders);

            return $restored->text;
        };

        $result = $output->getResult();
        $restoredResult = match (true) {
            $result instanceof TextResult => new TextResult($restore($result->getContent()), $result->getSignature(), $result->getPhase()),
            $result instanceof ObjectResult => new ObjectResult(
                (new StructuredRestorer($restore(...)))->restoreOutput($result->getContent()),
                null !== $result->getText() ? $restore($result->getText()) : null,
            ),
            default => null,
        };
        if (null === $restoredResult) {
            return;
        }

        $this->carryOver($result, $restoredResult);
        $restoredResult->getMetadata()->add(self::METADATA_KEY, ['unknown_placeholders' => array_values(array_unique($unknown))]);
        $output->setResult($restoredResult);
    }

    /**
     * @return list<string>
     */
    private function textsOf(MessageInterface $message): array
    {
        if ($message instanceof SystemMessage) {
            $content = $message->getContent();

            return \is_string($content) ? [$content] : [];
        }

        if (!$message instanceof UserMessage && !$message instanceof AssistantMessage && !$message instanceof ToolCallMessage) {
            throw new UnsupportedContentException(\sprintf('Unsupported message type "%s".', get_debug_type($message)));
        }

        $texts = [];
        foreach ($message->getContent() as $content) {
            if ($content instanceof Text) {
                $texts[] = $content->getText();
            } elseif (!$message instanceof AssistantMessage) {
                throw new UnsupportedContentException(\sprintf('Content of type "%s" cannot be redacted. Extract its text locally and send text only.', get_debug_type($content)));
            }
        }

        return $texts;
    }

    /**
     * Rebuilds the message with the next redacted texts, keeping id, metadata and non-text parts.
     *
     * @param list<string> $redactedTexts consumed from the front
     */
    private function withTexts(MessageInterface $message, array &$redactedTexts): MessageInterface
    {
        if ($message instanceof SystemMessage) {
            if (!\is_string($message->getContent())) {
                return $message;
            }
            $rebuilt = new SystemMessage((string) array_shift($redactedTexts));
        } else {
            \assert($message instanceof UserMessage || $message instanceof AssistantMessage || $message instanceof ToolCallMessage);
            $content = [];
            foreach ($message->getContent() as $part) {
                $content[] = $part instanceof Text
                    ? new Text((string) array_shift($redactedTexts), $part->getSignature(), $part->getPhase())
                    : $part;
            }
            $rebuilt = match (true) {
                $message instanceof UserMessage => new UserMessage(...$content),
                $message instanceof AssistantMessage => new AssistantMessage(...$content),
                $message instanceof ToolCallMessage => new ToolCallMessage($message->getToolCall(), ...$content),
            };
        }

        $rebuilt->getMetadata()->merge($message->getMetadata());

        return $rebuilt->withId($message->getId());
    }

    private function carryOver(BaseResult $from, BaseResult $to): void
    {
        $to->getMetadata()->merge($from->getMetadata());
        $raw = $from->getRawResult();
        if (null !== $raw) {
            $to->setRawResult($raw);
        }
    }
}
