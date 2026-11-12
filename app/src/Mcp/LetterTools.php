<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Explanation\ExplainedLetter;
use App\Explanation\ExplanationFailedException;
use App\Explanation\ExplanationLanguage;
use App\Explanation\ExplanationStyle;
use App\Explanation\ExplanationTimeLimit;
use App\Explanation\LetterExplainer;
use App\Explanation\PlaceholderExplainer;
use App\Extraction\ExtractedText;
use App\Extraction\ExtractionChain;
use App\Extraction\ExtractionFailedException;
use App\Extraction\ExtractionMethod;
use App\Extraction\UnsupportedDocumentException;
use Briefklar\PiiShield\EntityType;
use Briefklar\PiiShield\Exception\ExceptionInterface as PiiShieldException;
use Briefklar\PiiShield\PiiShieldInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\MimeTypes;

/**
 * The tools of the MCP server "briefklar" (config/packages/mcp.yaml, issue #64).
 *
 * Expected failures (letter not allowed, unreadable, provider down) become ToolCallException, which the
 * client receives as a tool result with isError instead of a protocol error. Messages never contain
 * letter content, and nothing of the letter is logged.
 */
final readonly class LetterTools
{
    private const string NOTICE = 'This is an explanation, not legal advice. Compare deadlines and amounts with the original letter.';

    /** JSON Schema of explain_letter_file and explain_letter results (structuredContent). */
    private const array EXPLANATION_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'explanation' => [
                'type' => 'object',
                'properties' => [
                    'sender' => ['type' => 'string'],
                    'subject' => ['type' => 'string'],
                    'summary' => ['type' => 'string'],
                    'actionRequired' => ['type' => 'boolean'],
                    'actions' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'deadline' => ['type' => ['string', 'null'], 'description' => 'As stated in the letter, never computed.'],
                    'amount' => ['type' => ['string', 'null']],
                    'legalReferences' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'questionsToAsk' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['sender', 'subject', 'summary', 'actionRequired', 'actions', 'deadline', 'amount', 'legalReferences', 'questionsToAsk'],
            ],
            'checks' => [
                'type' => 'object',
                'description' => 'What the server checked against the letter. Warn the user about anything not confirmed.',
                'properties' => [
                    'deadlineStatedInLetter' => ['type' => 'boolean'],
                    'inventedDates' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Dates in the text that are not in the letter.'],
                    'inventedPlaceholders' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'actionAdvice' => ['type' => 'string', 'enum' => ['steps', 'nothing_to_do', 'unclear']],
                    'readByOcr' => ['type' => 'boolean'],
                ],
                'required' => ['deadlineStatedInLetter', 'inventedDates', 'inventedPlaceholders', 'actionAdvice', 'readByOcr'],
            ],
            'notice' => ['type' => 'string'],
        ],
        'required' => ['explanation', 'checks', 'notice'],
    ];

    public function __construct(
        private LetterDirectory $letters,
        private ExtractionChain $extraction,
        private PlaceholderExplainer $placeholderExplainer,
        private LetterExplainer $explainer,
        private PiiShieldInterface $shield,
        private LoggerInterface $logger,
        private ExplanationTimeLimit $timeLimit,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'explain_letter_file',
        title: 'Explain a letter from the letter folder',
        description: 'Reads an official letter (PDF, PNG, JPG, TIFF) from the server\'s letter folder, runs OCR locally, redacts personal data and explains it: sender, subject, summary, steps, deadline, amount, legal references, questions to ask. The letter never enters your context, and the answer keeps placeholders like ⟦PERSON_1⟧ or ⟦IBAN_1⟧ instead of personal data. Deadline, amount and legal references are copied from the letter; never compute a date from them.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: false, openWorldHint: true),
        outputSchema: self::EXPLANATION_SCHEMA,
    )]
    public function explainLetterFile(
        #[Schema(description: 'File name relative to the letter folder, e.g. "strafverfuegung.pdf".')] string $path,
        #[Schema(description: 'standard, or easy_language for very short sentences ("Leichte Sprache").', enum: ['standard', 'easy_language'])] string $style = 'standard',
        #[Schema(description: 'Language of the explanation.', enum: ['de', 'en'])] string $language = 'de',
    ): array {
        // Over HTTP, PHP's 30 s limit would cut off OCR and a local model (issue seen with Claude Code).
        $this->timeLimit->apply();
        try {
            $file = $this->letters->resolve($path);
        } catch (LetterNotAllowedException $e) {
            throw new ToolCallException($e->getMessage(), previous: $e);
        }

        try {
            $letter = $this->extraction->extract($file, (string) (new MimeTypes())->guessMimeType($file));
        } catch (UnsupportedDocumentException|ExtractionFailedException $e) {
            $this->logger->warning('MCP letter extraction failed: {reason}', ['reason' => $e->getMessage()]);
            throw new ToolCallException('The letter could not be read. Try a sharper scan or a PDF.', previous: $e);
        }

        return $this->explained(fn (): ExplainedLetter => $this->placeholderExplainer->explain($letter, self::style($style), self::language($language)));
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'explain_letter',
        title: 'Explain letter text',
        description: 'Explains the text of an official letter. Personal data is redacted before the explanation model sees it, but you have already seen the text: prefer explain_letter_file for letters the user has not shared with you. The answer contains the original values.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: false, openWorldHint: true),
        outputSchema: self::EXPLANATION_SCHEMA,
    )]
    public function explainLetter(
        #[Schema(description: 'Full text of the letter.')] string $text,
        #[Schema(description: 'standard, or easy_language for very short sentences ("Leichte Sprache").', enum: ['standard', 'easy_language'])] string $style = 'standard',
        #[Schema(description: 'Language of the explanation.', enum: ['de', 'en'])] string $language = 'de',
    ): array {
        if ('' === trim($text)) {
            throw new ToolCallException('The letter text is empty.');
        }
        $this->timeLimit->apply();
        $letter = new ExtractedText($text, ExtractionMethod::PlainText);

        return $this->explained(fn (): ExplainedLetter => $this->explainer->explain($letter, self::style($style), self::language($language)));
    }

    /**
     * @return array{redactedText: string, types: list<string>}
     */
    #[McpTool(
        name: 'redact_text',
        title: 'Redact personal data',
        description: 'Replaces personal data (names, addresses, social security and tax numbers, IBANs, file numbers, …) with typed placeholders such as ⟦PERSON_1⟧, the same way briefklar does before explaining a letter. Returns the redacted text and the placeholder types, never the original values. There is no restore: this server keeps no mapping.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    )]
    public function redactText(#[Schema(description: 'Text with personal data.')] string $text): array
    {
        try {
            $result = $this->shield->redact($text);
        } catch (PiiShieldException $e) {
            throw new ToolCallException('Redaction failed.', previous: $e);
        }

        return [
            'redactedText' => $result->redactedText,
            'types' => array_map(static fn (EntityType $t): string => $t->value, $result->foundTypes()),
        ];
    }

    /**
     * @param \Closure(): ExplainedLetter $explain
     *
     * @return array<string, mixed>
     */
    private function explained(\Closure $explain): array
    {
        try {
            $result = $explain();
        } catch (ExplanationFailedException $e) {
            $this->logger->error('MCP letter explanation failed: {reason}', ['reason' => $e->getMessage()]);
            throw new ToolCallException('The explanation did not work right now. Please try again in a minute.', previous: $e);
        }

        return [
            'explanation' => get_object_vars($result->explanation),
            'checks' => [
                'deadlineStatedInLetter' => $result->deadlineStatedInLetter,
                'inventedDates' => $result->inventedDates,
                'inventedPlaceholders' => $result->inventedPlaceholders,
                'actionAdvice' => $result->actionAdvice()->value,
                'readByOcr' => ExtractionMethod::Ocr === $result->extractionMethod,
            ],
            'notice' => self::NOTICE,
        ];
    }

    private static function style(string $style): ExplanationStyle
    {
        return ExplanationStyle::tryFrom($style) ?? throw new ToolCallException('style must be "standard" or "easy_language".');
    }

    private static function language(string $language): ExplanationLanguage
    {
        return ExplanationLanguage::tryFrom($language) ?? throw new ToolCallException('language must be "de" or "en".');
    }
}
