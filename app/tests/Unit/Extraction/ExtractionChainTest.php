<?php

declare(strict_types=1);

namespace App\Tests\Unit\Extraction;

use App\Extraction\ExtractedText;
use App\Extraction\ExtractionChain;
use App\Extraction\ExtractionFailedException;
use App\Extraction\ExtractionMethod;
use App\Extraction\TextExtractorInterface;
use App\Extraction\UnsupportedDocumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExtractionChainTest extends TestCase
{
    public function testFirstExtractorWithUsableTextWins(): void
    {
        $chain = new ExtractionChain([
            self::extractor('application/pdf', null),
            self::extractor('application/pdf', 'from ocr', ExtractionMethod::Ocr),
            self::extractor('application/pdf', 'never reached'),
        ]);

        $result = $chain->extract('/irrelevant', 'application/pdf');

        self::assertSame('from ocr', $result->text);
        self::assertSame(ExtractionMethod::Ocr, $result->method);
    }

    public function testSkipsExtractorsThatDoNotSupportTheMimeType(): void
    {
        $chain = new ExtractionChain([
            self::extractor('application/pdf', 'pdf text'),
            self::extractor('image/png', 'image text', ExtractionMethod::Ocr),
        ]);

        self::assertSame('image text', $chain->extract('/irrelevant', 'image/png')->text);
    }

    public function testUnsupportedMimeTypeThrows(): void
    {
        $chain = new ExtractionChain([self::extractor('application/pdf', 'pdf text')]);

        $this->expectException(UnsupportedDocumentException::class);
        $chain->extract('/irrelevant', 'application/zip');
    }

    public function testThrowsWhenNoExtractorProducesText(): void
    {
        $chain = new ExtractionChain([
            self::extractor('image/png', null),
            self::extractor('image/png', "  \n\t "),
        ]);

        $this->expectException(ExtractionFailedException::class);
        $chain->extract('/irrelevant', 'image/png');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function normalizationCases(): iterable
    {
        yield 'windows line endings' => ["Zeile 1\r\nZeile 2", "Zeile 1\nZeile 2"];
        yield 'trailing spaces' => ["Zeile 1   \nZeile 2\t", "Zeile 1\nZeile 2"];
        yield 'many blank lines' => ["Absatz 1\n\n\n\n\nAbsatz 2", "Absatz 1\n\nAbsatz 2"];
        yield 'form feed between pages' => ["Seite 1\fSeite 2", "Seite 1\n\nSeite 2"];
        yield 'leading and trailing whitespace' => ["\n\n  Text  \n\n", 'Text'];
        yield 'non-breaking space' => ["110,00\u{00A0}Euro", '110,00 Euro'];
    }

    #[DataProvider('normalizationCases')]
    public function testNormalizesWhitespace(string $raw, string $expected): void
    {
        $chain = new ExtractionChain([self::extractor('text/plain', $raw)]);

        self::assertSame($expected, $chain->extract('/irrelevant', 'text/plain')->text);
    }

    private static function extractor(string $mimeType, ?string $text, ExtractionMethod $method = ExtractionMethod::PdfTextLayer): TextExtractorInterface
    {
        return new readonly class($mimeType, $text, $method) implements TextExtractorInterface {
            public function __construct(private string $mimeType, private ?string $text, private ExtractionMethod $method)
            {
            }

            public function supports(string $mimeType): bool
            {
                return $mimeType === $this->mimeType;
            }

            public function extract(string $path): ?ExtractedText
            {
                return null === $this->text ? null : new ExtractedText($this->text, $this->method);
            }
        };
    }
}
