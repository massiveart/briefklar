<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Demo\Mcp;

use Briefklar\PiiShield\Demo\ServiceApi;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * MCP tools of the pii-shield server (STDIO: bin/console mcp:server; HTTP: /mcp).
 * Same operations and rules as the HTTP API; the mapping never leaves the server.
 */
final class PiiShieldTools
{
    public function __construct(
        private readonly ServiceApi $api,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'redact_text',
        title: 'Redact personal data',
        description: 'Replaces personal data (names, addresses, IDs, IBANs, …) with placeholders such as ⟦PERSON_1⟧, so the text can be given to any model. Returns the redacted text, a vaultId for restore_text and the placeholder types; never the original values.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
    )]
    public function redactText(
        #[Schema(description: 'Text with personal data, e.g. a letter.')] string $text,
        #[Schema(description: 'regex (patterns, offline), model (the server\'s configured model) or both.', enum: ['regex', 'model', 'both'])] string $detection = 'regex',
    ): array {
        return $this->call('redact', ['text' => $text, 'detection' => $detection]);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'restore_text',
        title: 'Restore placeholders',
        description: 'Puts the original values back into a text with placeholders (typically a model answer), using the vaultId from redact_text. Placeholders the model invented are reported.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function restoreText(
        #[Schema(description: 'Text containing placeholders like ⟦PERSON_1⟧.')] string $text,
        #[Schema(description: 'vaultId returned by redact_text.')] string $vaultId,
    ): array {
        return $this->call('restore', ['text' => $text, 'vaultId' => $vaultId]);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'mask_text',
        title: 'Mask personal data irreversibly',
        description: 'Masks personal data for logs and previews (e.g. AT61 **** **** **** 3201, [PERSON]). Nothing is stored; there is no way back.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function maskText(
        #[Schema(description: 'Text with personal data.')] string $text,
    ): array {
        return $this->call('mask', ['text' => $text]);
    }

    /**
     * @param list<string> $examples
     * @param list<string> $counterExamples
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'learn_entity_type',
        title: 'Learn a new kind of personal data',
        description: 'Teaches pii-shield a new entity type from examples ("mask things like this too"). A pattern is derived from the examples; plain words become model-only types. Use synthetic examples only: they are stored. dryRun previews without saving.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    )]
    public function learnEntityType(
        #[Schema(description: 'UPPER_SNAKE_CASE name, becomes the placeholder name, e.g. INSURANCE_NUMBER.', pattern: '^[A-Z][A-Z0-9_]{1,39}$')] string $name,
        #[Schema(description: 'Examples of the value, one per item.', items: ['type' => 'string'])] array $examples,
        #[Schema(description: 'What it is; helps the model.')] string $description = '',
        #[Schema(description: 'Similar values that are not this type.', items: ['type' => 'string'])] array $counterExamples = [],
        #[Schema(description: 'Only derive and validate, do not save.')] bool $dryRun = false,
    ): array {
        return $this->call('learn', ['name' => $name, 'description' => $description, 'examples' => $examples, 'counterExamples' => $counterExamples, 'dryRun' => $dryRun]);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'list_entity_types',
        title: 'List learned entity types',
        description: 'Lists the entity types learned from examples, with their patterns.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function listEntityTypes(): array
    {
        return $this->call('entities', []);
    }

    /**
     * @param 'redact'|'restore'|'mask'|'learn'|'entities' $operation
     * @param array<string, mixed>                         $body
     *
     * @return array<string, mixed>
     */
    private function call(string $operation, array $body): array
    {
        $response = $this->api->operation($operation, $body);
        if ($response['status'] >= 400) {
            $error = $response['body']['error'] ?? 'The operation failed.';
            throw new ToolCallException(\is_string($error) ? $error : 'The operation failed.');
        }

        return $response['body'] ?? [];
    }
}
