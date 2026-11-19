<?php

declare(strict_types=1);

namespace App\Tests\Unit\Extraction;

use App\Extraction\ExtractionMethod;
use App\Extraction\Pdf\PdfTextLayerExtractor;
use App\Tests\Support\PdfFixtureFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PdfTextLayerExtractorTest extends TestCase
{
    public function testExtractsTextLayerOfFixture01(): void
    {
        $pdf = PdfFixtureFactory::toTempFile(PdfFixtureFactory::textPdf(PdfFixtureFactory::letter('01-strafverfuegung')), '.pdf');

        $result = (new PdfTextLayerExtractor())->extract($pdf);

        self::assertNotNull($result);
        self::assertSame(ExtractionMethod::PdfTextLayer, $result->method);
        foreach (['STRAFVERFÜGUNG', '§ 49 VStG', '110,00 Euro', 'binnen zwei Wochen', 'BHMS-VK-2026/48213'] as $expected) {
            self::assertStringContainsString($expected, $result->text);
        }
    }

    public function testReturnsNullForPdfWithoutUsableTextLayer(): void
    {
        $pdf = PdfFixtureFactory::toTempFile(PdfFixtureFactory::textPdf('Seite 1'), '.pdf');

        self::assertNull((new PdfTextLayerExtractor())->extract($pdf));
    }

    public function testReturnsNullForBrokenPdf(): void
    {
        $pdf = PdfFixtureFactory::toTempFile('%PDF-1.4 this is not a pdf', '.pdf');

        self::assertNull((new PdfTextLayerExtractor())->extract($pdf));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function decompressionBombs(): iterable
    {
        yield 'one stream inflating to 300 MB' => [300, 1];
        yield '150 streams of 2 MB each' => [2, 150];
    }

    /**
     * Issue #84: a small PDF that inflates beyond the memory limit must not end the process
     * (a fatal error cannot be caught); it is treated as a PDF without usable text layer.
     */
    #[DataProvider('decompressionBombs')]
    public function testDecompressionBombDoesNotExhaustMemory(int $inflatedMegabytes, int $streams): void
    {
        $pdf = PdfFixtureFactory::toTempFile(PdfFixtureFactory::decompressionBomb($inflatedMegabytes, $streams), '.pdf');
        self::assertLessThan(2_000_000, (int) filesize($pdf));
        $before = memory_get_usage();
        memory_reset_peak_usage();

        (new PdfTextLayerExtractor())->extract($pdf);

        self::assertLessThan(48 * 1024 * 1024, memory_get_peak_usage() - $before);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function mimeTypes(): iterable
    {
        yield 'pdf' => ['application/pdf', true];
        yield 'png' => ['image/png', false];
        yield 'jpeg' => ['image/jpeg', false];
        yield 'text' => ['text/plain', false];
    }

    #[DataProvider('mimeTypes')]
    public function testSupports(string $mimeType, bool $expected): void
    {
        self::assertSame($expected, (new PdfTextLayerExtractor())->supports($mimeType));
    }
}
