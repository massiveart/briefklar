<?php

declare(strict_types=1);

namespace App\Extraction\Pdf;

use App\Extraction\ExtractedText;
use App\Extraction\ExtractionMethod;
use App\Extraction\TextExtractorInterface;
use Smalot\PdfParser\Config;
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

    /**
     * Decompression bombs (issue #84): a small PDF can inflate to gigabytes, and running out of memory is
     * a fatal error no catch can handle. Each stream is decoded to at most this many bytes; a letter page's
     * text is a few kilobytes, fonts and images are not needed for the text and may be cut short.
     */
    private const int MAX_DECODED_STREAM_BYTES = 256 * 1024;

    /**
     * With the cap per stream, the number of streams bounds the memory. A letter has a handful; a PDF with
     * more is left to OCR, which renders it in a separate process with a timeout.
     */
    private const int MAX_STREAMS = 100;

    public function supports(string $mimeType): bool
    {
        return 'application/pdf' === $mimeType;
    }

    public function extract(string $path): ?ExtractedText
    {
        $raw = @file_get_contents($path);
        if (false === $raw || substr_count($raw, 'endstream') > self::MAX_STREAMS) {
            return null;
        }

        $config = new Config();
        $config->setDecodeMemoryLimit(self::MAX_DECODED_STREAM_BYTES);
        try {
            $text = (new Parser([], $config))->parseContent($raw)->getText();
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
