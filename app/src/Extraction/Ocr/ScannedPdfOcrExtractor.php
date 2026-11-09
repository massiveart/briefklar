<?php

declare(strict_types=1);

namespace App\Extraction\Ocr;

use App\Extraction\ExtractedText;
use App\Extraction\ExtractionMethod;
use App\Extraction\TextExtractorInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;

/**
 * OCR for PDFs without text layer: renders pages with pdftoppm (poppler), then runs Tesseract per page.
 * Rendered page images are deleted before the method returns.
 */
#[AsTaggedItem(priority: 20)]
final readonly class ScannedPdfOcrExtractor implements TextExtractorInterface
{
    public function __construct(
        private TesseractOcr $ocr,
        private OcrTextCorrector $corrector,
        private string $pdftoppmBinary = 'pdftoppm',
        private int $dpi = 300,
        private int $maxPages = 10,
        private ?string $tempDir = null,
    ) {
    }

    public function supports(string $mimeType): bool
    {
        return 'application/pdf' === $mimeType;
    }

    public function extract(string $path): ?ExtractedText
    {
        $workDir = ($this->tempDir ?? sys_get_temp_dir()).'/briefklar_ocr_'.bin2hex(random_bytes(8));
        if (!mkdir($workDir, 0700) && !is_dir($workDir)) {
            throw new \RuntimeException('Could not create OCR work directory.');
        }

        try {
            $render = new Process([
                $this->pdftoppmBinary, '-r', (string) $this->dpi, '-gray', '-png', '-l', (string) $this->maxPages, $path, $workDir.'/page',
            ], timeout: 120);

            try {
                $render->mustRun();
            } catch (ProcessException) {
                return null; // not a renderable PDF
            }

            $pages = self::files($workDir.'/page-*.png');
            sort($pages, \SORT_NATURAL);

            $text = implode("\f", array_map($this->ocr->recognize(...), $pages));

            return '' === trim($text) ? null : new ExtractedText($this->corrector->correct($text), ExtractionMethod::Ocr);
        } finally {
            array_map(unlink(...), self::files($workDir.'/*'));
            rmdir($workDir);
        }
    }

    /**
     * @return list<string>
     */
    private static function files(string $pattern): array
    {
        $files = glob($pattern);

        return false === $files ? [] : $files;
    }
}
