<?php

declare(strict_types=1);

namespace App\Extraction;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Turns an uploaded letter into plain text, locally (see docs/adr/0002-local-ocr-before-redaction.md).
 *
 * Order: PDF text layer, then OCR of scanned PDFs, then OCR of images. The first usable result wins.
 */
final readonly class ExtractionChain
{
    /**
     * @param iterable<TextExtractorInterface> $extractors
     */
    public function __construct(
        #[AutowireIterator(TextExtractorInterface::TAG)]
        private iterable $extractors,
    ) {
    }

    /**
     * @throws UnsupportedDocumentException if no extractor handles the MIME type
     * @throws ExtractionFailedException    if no extractor produced text
     */
    public function extract(string $path, string $mimeType): ExtractedText
    {
        $supported = false;
        foreach ($this->extractors as $extractor) {
            if (!$extractor->supports($mimeType)) {
                continue;
            }
            $supported = true;

            $result = $extractor->extract($path);
            if (null === $result) {
                continue;
            }

            $text = self::normalize($result->text);
            if ('' !== $text) {
                return new ExtractedText($text, $result->method);
            }
        }

        if (!$supported) {
            throw UnsupportedDocumentException::forMimeType($mimeType);
        }

        throw new ExtractionFailedException('No text could be extracted from the document.');
    }

    private static function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\f", "\u{00A0}"], ["\n", "\n", "\n\n", ' '], $text);
        $text = (string) preg_replace('/[ \t]+$/mu', '', $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }
}
