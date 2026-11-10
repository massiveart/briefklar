<?php

declare(strict_types=1);

namespace App\Extraction;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One way to turn a document into text. Extractors are tried in priority order by {@see ExtractionChain}.
 */
#[AutoconfigureTag(self::TAG)]
interface TextExtractorInterface
{
    public const string TAG = 'app.text_extractor';

    /**
     * @param string $mimeType MIME type detected from the file content, never the client-provided one
     */
    public function supports(string $mimeType): bool;

    /**
     * Returns null if this extractor cannot produce usable text, so the next extractor is tried.
     *
     * @throws ExtractionFailedException on infrastructure errors (e.g. OCR binary missing)
     */
    public function extract(string $path): ?ExtractedText;
}
