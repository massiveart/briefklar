<?php

declare(strict_types=1);

namespace App\Tests\Integration\Extraction;

use App\Extraction\ExtractionMethod;
use App\Extraction\Ocr\ImageOcrExtractor;
use App\Extraction\Ocr\OcrTextCorrector;
use App\Extraction\Ocr\ScannedPdfOcrExtractor;
use App\Extraction\Ocr\TesseractOcr;
use App\Tests\Support\PdfFixtureFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Runs the real Tesseract and pdftoppm binaries (present in the app container and in CI).
 */
final class OcrExtractionTest extends TestCase
{
    private const array MUST_READ = ['Bezirkshauptmannschaft Musterstadt', '110,00 Euro', '§ 49 VStG', '§ 20 Abs. 2 StVO', 'binnen zwei Wochen'];

    private string $tempDir;

    protected function setUp(): void
    {
        foreach (['tesseract', 'pdftoppm'] as $binary) {
            if (null === (new ExecutableFinder())->find($binary)) {
                self::markTestSkipped(\sprintf('"%s" is not installed; run the tests in the app container.', $binary));
            }
        }

        $this->tempDir = sys_get_temp_dir().'/briefklar_test_'.bin2hex(random_bytes(4));
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        if (isset($this->tempDir) && is_dir($this->tempDir)) {
            rmdir($this->tempDir); // fails if an extractor left files behind
        }
    }

    public function testReadsScannedPdfAndLeavesNoFilesBehind(): void
    {
        $pdf = PdfFixtureFactory::toTempFile(PdfFixtureFactory::scannedPdf(PdfFixtureFactory::letter('01-strafverfuegung')), '.pdf');

        $result = $this->scannedPdfExtractor()->extract($pdf);

        self::assertNotNull($result);
        self::assertSame(ExtractionMethod::Ocr, $result->method);
        foreach (self::MUST_READ as $expected) {
            self::assertStringContainsString($expected, $result->text);
        }
        self::assertSame([], glob($this->tempDir.'/*'));
    }

    public function testReadsImage(): void
    {
        $png = PdfFixtureFactory::toTempFile(PdfFixtureFactory::png(PdfFixtureFactory::letter('01-strafverfuegung')), '.png');

        $result = (new ImageOcrExtractor(new TesseractOcr(), new OcrTextCorrector()))->extract($png);

        self::assertNotNull($result);
        self::assertSame(ExtractionMethod::Ocr, $result->method);
        foreach (self::MUST_READ as $expected) {
            self::assertStringContainsString($expected, $result->text);
        }
    }

    public function testBrokenPdfYieldsNullAndLeavesNoFilesBehind(): void
    {
        $pdf = PdfFixtureFactory::toTempFile('%PDF-1.4 this is not a pdf', '.pdf');

        self::assertNull($this->scannedPdfExtractor()->extract($pdf));
        self::assertSame([], glob($this->tempDir.'/*'));
    }

    /**
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function mimeTypes(): iterable
    {
        yield 'pdf' => ['application/pdf', true, false];
        yield 'png' => ['image/png', false, true];
        yield 'jpeg' => ['image/jpeg', false, true];
        yield 'tiff' => ['image/tiff', false, true];
        yield 'gif' => ['image/gif', false, false];
        yield 'text' => ['text/plain', false, false];
    }

    #[DataProvider('mimeTypes')]
    public function testSupports(string $mimeType, bool $scannedPdf, bool $image): void
    {
        self::assertSame($scannedPdf, $this->scannedPdfExtractor()->supports($mimeType));
        self::assertSame($image, (new ImageOcrExtractor(new TesseractOcr(), new OcrTextCorrector()))->supports($mimeType));
    }

    private function scannedPdfExtractor(): ScannedPdfOcrExtractor
    {
        return new ScannedPdfOcrExtractor(new TesseractOcr(), new OcrTextCorrector(), tempDir: $this->tempDir);
    }
}
