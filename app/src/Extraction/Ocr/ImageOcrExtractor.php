<?php

declare(strict_types=1);

namespace App\Extraction\Ocr;

use App\Extraction\ExtractedText;
use App\Extraction\ExtractionMethod;
use App\Extraction\TextExtractorInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * OCR for photos and scans uploaded as images.
 */
#[AsTaggedItem(priority: 10)]
final readonly class ImageOcrExtractor implements TextExtractorInterface
{
    private const array MIME_TYPES = ['image/png', 'image/jpeg', 'image/tiff'];

    public function __construct(
        private TesseractOcr $ocr,
        private OcrTextCorrector $corrector,
    ) {
    }

    public function supports(string $mimeType): bool
    {
        return \in_array($mimeType, self::MIME_TYPES, true);
    }

    public function extract(string $path): ?ExtractedText
    {
        $text = $this->ocr->recognize($path);

        return '' === trim($text) ? null : new ExtractedText($this->corrector->correct($text), ExtractionMethod::Ocr);
    }
}
