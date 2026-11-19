<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Sample\LetterPdf;

/**
 * Builds documents from the synthetic fixture letters at test time, so no binary
 * fixtures need to be committed. The rendering itself is App\Sample\LetterPdf,
 * the same code that serves the sample letters on the upload page.
 */
final class PdfFixtureFactory
{
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
        return (new LetterPdf())->text($text);
    }

    /**
     * Image-only PDF without text layer, needs pdftoppm.
     */
    public static function scannedPdf(string $text, int $dpi = 200): string
    {
        return (new LetterPdf())->scan($text, $dpi);
    }

    /**
     * First page as PNG, like a photo of the letter; needs pdftoppm.
     */
    public static function png(string $text, int $dpi = 200): string
    {
        return (new LetterPdf())->photo($text, $dpi);
    }

    /**
     * A decompression bomb: a small, valid PDF whose page content is $streams Flate streams that each
     * inflate to $inflatedMegabytes of spaces. Compressed in chunks, so building it needs little memory.
     */
    public static function decompressionBomb(int $inflatedMegabytes, int $streams = 1): string
    {
        return self::rawPdf(self::bombStreams($inflatedMegabytes), $streams, 595);
    }

    /**
     * A PDF of a few hundred bytes with one blank page of $points × $points (7200 = 2.54 m square):
     * rendered at 300 dpi that is a 30000 × 30000 pixel image.
     */
    public static function hugePage(int $points): string
    {
        return self::rawPdf(null, 0, $points);
    }

    private static function bombStreams(int $inflatedMegabytes): string
    {
        $chunk = str_repeat(' ', 1024 * 1024);
        $deflate = deflate_init(\ZLIB_ENCODING_DEFLATE, ['level' => 9]);
        if (false === $deflate) {
            throw new \RuntimeException('zlib is not available.');
        }
        $compressed = '';
        for ($i = 0; $i < $inflatedMegabytes; ++$i) {
            $compressed .= (string) deflate_add($deflate, $chunk, \ZLIB_NO_FLUSH);
        }
        $compressed .= (string) deflate_add($deflate, '(Bombe) Tj', \ZLIB_FINISH);

        return $compressed;
    }

    private static function rawPdf(?string $compressed, int $streams, int $size): string
    {
        $contents = $streams > 0 ? range(4, 3 + $streams) : []; // range(4, 3) would count down
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.$size.' '.$size.'] /Contents ['.implode(' ', array_map(static fn (int $n): string => $n.' 0 R', $contents)).'] >>',
        ];
        foreach ($contents as $n) {
            $objects[$n] = '<< /Length '.\strlen((string) $compressed)." /Filter /FlateDecode >>\nstream\n".$compressed."\nendstream";
        }

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $n => $body) {
            $offsets[$n] = \strlen($pdf);
            $pdf .= $n." 0 obj\n".$body."\nendobj\n";
        }
        $xref = \strlen($pdf);
        $pdf .= "xref\n0 ".(\count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= \sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size ".(\count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    /**
     * Writes bytes to a temporary file that is removed when the test process ends.
     */
    public static function toTempFile(string $bytes, string $suffix): string
    {
        $path = sys_get_temp_dir().'/clarifile_fixture_'.bin2hex(random_bytes(6)).$suffix;
        file_put_contents($path, $bytes);
        register_shutdown_function(static fn () => is_file($path) && unlink($path));

        return $path;
    }
}
