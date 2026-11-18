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
     * Writes bytes to a temporary file that is removed when the test process ends.
     */
    public static function toTempFile(string $bytes, string $suffix): string
    {
        $path = sys_get_temp_dir().'/briefklar_fixture_'.bin2hex(random_bytes(6)).$suffix;
        file_put_contents($path, $bytes);
        register_shutdown_function(static fn () => is_file($path) && unlink($path));

        return $path;
    }
}
