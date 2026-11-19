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
    /**
     * Longest side of a rendered page in pixels: A4 at 300 dpi. pdftoppm scales every page to it, so a
     * tiny PDF with a page of metres (issue #84) costs no more than a letter; smaller pages get enlarged.
     */
    private const int MAX_PAGE_PIXELS = 3508;

    /**
     * Work directories older than this are left over from a run that ended with a fatal error (time limit),
     * which skips the finally block; their page images contain personal data and are removed.
     */
    private const int STALE_AFTER_SECONDS = 3600;

    public function __construct(
        private TesseractOcr $ocr,
        private OcrTextCorrector $corrector,
        private string $pdftoppmBinary = 'pdftoppm',
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
        $tempDir = $this->tempDir ?? sys_get_temp_dir();
        self::removeStaleWorkDirs($tempDir);

        $workDir = $tempDir.'/clarifile_ocr_'.bin2hex(random_bytes(8));
        if (!mkdir($workDir, 0700) && !is_dir($workDir)) {
            throw new \RuntimeException('Could not create OCR work directory.');
        }

        try {
            $render = new Process([
                $this->pdftoppmBinary, '-scale-to', (string) self::MAX_PAGE_PIXELS,
                '-gray', '-png', '-l', (string) $this->maxPages, $path, $workDir.'/page',
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

    private static function removeStaleWorkDirs(string $tempDir): void
    {
        foreach (self::files($tempDir.'/clarifile_ocr_*') as $dir) {
            $modified = @filemtime($dir);
            if (!is_dir($dir) || false === $modified || $modified > time() - self::STALE_AFTER_SECONDS) {
                continue;
            }
            array_map(static fn (string $file): bool => @unlink($file), self::files($dir.'/*'));
            @rmdir($dir);
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
