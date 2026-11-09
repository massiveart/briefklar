<?php

declare(strict_types=1);

namespace App\Extraction\Pdf;

use App\Extraction\ExtractedText;
use App\Extraction\ExtractionMethod;
use App\Extraction\TextExtractorInterface;
use Smalot\PdfParser\Parser;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Reads the embedded text layer of digitally created PDFs. Fast and exact, no OCR errors.
 * Scanned PDFs have no (or almost no) text layer and fall through to OCR.
 */
#[AsTaggedItem(priority: 30)]
final readonly class PdfTextLayerExtractor implements TextExtractorInterface
{
    /**
     * Below this many letters the PDF is treated as scanned (scanners sometimes add a page number or a stamp as text).
     */
    private const int MIN_LETTERS = 50;

    public function supports(string $mimeType): bool
    {
        return 'application/pdf' === $mimeType;
    }

    public function extract(string $path): ?ExtractedText
    {
        try {
            $text = (new Parser())->parseFile($path)->getText();
        } catch (\Throwable) {
            // smalot throws plain \Exception for malformed files; let OCR try instead.
            return null;
        }

        if (preg_match_all('/\pL/u', $text) < self::MIN_LETTERS) {
            return null;
        }

        return new ExtractedText($text, ExtractionMethod::PdfTextLayer);
    }
}
