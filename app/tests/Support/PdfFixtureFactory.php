<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Process\Process;

/**
 * Builds PDFs from the synthetic fixture letters at test time, so no binary
 * fixtures need to be committed.
 *
 * - textPdf(): PDF with a real text layer (like a letter exported from an office suite)
 * - scannedPdf(): image-only PDF without text layer (like a scanner output), needs pdftoppm
 */
final class PdfFixtureFactory
{
    private const int PAGE_WIDTH = 595;
    private const int PAGE_HEIGHT = 842;
    private const int LINES_PER_PAGE = 60;
    private const int CHARS_PER_LINE = 95;

    /**
     * The shared synthetic letters in /fixtures/letters.
     */
    public static function letter(string $name): string
    {
        $path = \dirname(__DIR__, 3).'/fixtures/letters/'.$name.'.txt';
        $text = file_get_contents($path);
        if (false === $text) {
            throw new \RuntimeException(\sprintf('Fixture "%s" not found.', $path));
        }

        return $text;
    }

    public static function textPdf(string $text): string
    {
        $lines = [];
        foreach (self::lines($text) as $paragraph) {
            $wrapped = '' === $paragraph ? [''] : explode("\n", wordwrap($paragraph, self::CHARS_PER_LINE, "\n", true));
            array_push($lines, ...$wrapped);
        }

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];
        $kids = [];
        $next = 4;
        foreach (array_chunk($lines, self::LINES_PER_PAGE) as $pageLines) {
            $content = "BT\n/F1 10 Tf\n13 TL\n50 800 Td\n";
            foreach ($pageLines as $line) {
                $content .= '('.self::escape($line).") Tj T*\n";
            }
            $content .= 'ET';

            $pageId = $next++;
            $contentId = $next++;
            $kids[] = $pageId.' 0 R';
            $objects[$pageId] = \sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] /Resources << /Font << /F1 3 0 R >> >> /Contents %d 0 R >>', self::PAGE_WIDTH, self::PAGE_HEIGHT, $contentId);
            $objects[$contentId] = self::stream('', $content);
        }
        $objects[2] = \sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', $kids), \count($kids));

        return self::build($objects);
    }

    /**
     * Renders the text PDF to grayscale JPEGs and wraps them into an image-only PDF.
     */
    public static function scannedPdf(string $text, int $dpi = 200): string
    {
        $dir = sys_get_temp_dir().'/briefklar_fixture_'.bin2hex(random_bytes(6));
        mkdir($dir);

        try {
            file_put_contents($dir.'/source.pdf', self::textPdf($text));
            (new Process(['pdftoppm', '-r', (string) $dpi, '-gray', '-jpeg', $dir.'/source.pdf', $dir.'/page']))->mustRun();
            $images = self::files($dir.'/page-*.jpg');
            sort($images, \SORT_NATURAL);

            $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>'];
            $kids = [];
            $next = 3;
            foreach ($images as $image) {
                $size = getimagesize($image);
                $bytes = file_get_contents($image);
                if (false === $size || false === $bytes) {
                    throw new \RuntimeException('Could not read rendered page.');
                }

                $pageId = $next++;
                $contentId = $next++;
                $imageId = $next++;
                $kids[] = $pageId.' 0 R';
                $objects[$pageId] = \sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] /Resources << /XObject << /Im1 %d 0 R >> >> /Contents %d 0 R >>', self::PAGE_WIDTH, self::PAGE_HEIGHT, $imageId, $contentId);
                $objects[$contentId] = self::stream('', \sprintf('q %d 0 0 %d 0 0 cm /Im1 Do Q', self::PAGE_WIDTH, self::PAGE_HEIGHT));
                $objects[$imageId] = self::stream(
                    \sprintf(
                        '/Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace %s /BitsPerComponent 8 /Filter /DCTDecode',
                        $size[0],
                        $size[1],
                        1 === ($size['channels'] ?? 3) ? '/DeviceGray' : '/DeviceRGB',
                    ),
                    $bytes,
                );
            }
            $objects[2] = \sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', $kids), \count($kids));

            return self::build($objects);
        } finally {
            array_map('unlink', self::files($dir.'/*'));
            rmdir($dir);
        }
    }

    /**
     * Renders the first page of the text PDF to a PNG, like a photo of the letter.
     */
    public static function png(string $text, int $dpi = 200): string
    {
        $dir = sys_get_temp_dir().'/briefklar_fixture_'.bin2hex(random_bytes(6));
        mkdir($dir);

        try {
            file_put_contents($dir.'/source.pdf', self::textPdf($text));
            (new Process(['pdftoppm', '-r', (string) $dpi, '-gray', '-png', '-singlefile', '-f', '1', $dir.'/source.pdf', $dir.'/page']))->mustRun();
            $png = file_get_contents($dir.'/page.png');
            if (false === $png) {
                throw new \RuntimeException('Could not read rendered page.');
            }

            return $png;
        } finally {
            array_map('unlink', self::files($dir.'/*'));
            rmdir($dir);
        }
    }

    /**
     * Writes bytes to a temporary file that is removed when the test process ends.
     */
    public static function toTempFile(string $bytes, string $suffix): string
    {
        $path = sys_get_temp_dir().'/briefklar_fixture_'.bin2hex(random_bytes(6)).$suffix;
        file_put_contents($path, $bytes);
        register_shutdown_function(static fn () => is_file($path) && unlink($path));

        return $path;
    }

    /**
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        $lines = preg_split('/\R/u', $text);

        return false === $lines ? [] : $lines;
    }

    /**
     * @return list<string>
     */
    private static function files(string $pattern): array
    {
        $files = glob($pattern);

        return false === $files ? [] : $files;
    }

    private static function escape(string $line): string
    {
        $encoded = mb_convert_encoding($line, 'Windows-1252', 'UTF-8');

        return strtr($encoded, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }

    private static function stream(string $dictionary, string $data): string
    {
        return \sprintf("<< %s /Length %d >>\nstream\n%s\nendstream", $dictionary, \strlen($data), $data);
    }

    /**
     * @param array<int, string> $objects
     */
    private static function build(array $objects): string
    {
        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = \strlen($pdf);
            $pdf .= \sprintf("%d 0 obj\n%s\nendobj\n", $id, $body);
        }

        $xref = \strlen($pdf);
        $pdf .= \sprintf("xref\n0 %d\n0000000000 65535 f \n", \count($objects) + 1);
        foreach ($offsets as $offset) {
            $pdf .= \sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.\sprintf("trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", \count($objects) + 1, $xref);
    }
}
