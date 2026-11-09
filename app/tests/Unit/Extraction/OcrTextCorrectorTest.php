<?php

declare(strict_types=1);

namespace App\Tests\Unit\Extraction;

use App\Extraction\Ocr\OcrTextCorrector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OcrTextCorrectorTest extends TestCase
{
    /**
     * Misreads observed with Tesseract 5 (deu) on fixture 01.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'dollar instead of paragraph' => ['gemäß $ 99 Abs. 3', 'gemäß § 99 Abs. 3'];
        yield 'eight-dollar instead of paragraph' => ['verletzt: 8$ 20 Abs. 2 StVO', 'verletzt: § 20 Abs. 2 StVO'];
        yield 'in parentheses' => ['erheben ($ 49 VStG).', 'erheben (§ 49 VStG).'];
        yield 'double paragraph' => ['$$ 1 bis 3', '§§ 1 bis 3'];
        yield 'missing space' => ['$49 VStG', '§ 49 VStG'];
        yield 'correct paragraph unchanged' => ['§ 49 VStG', '§ 49 VStG'];
        yield 'dollar amount unchanged' => ['USD 20$', 'USD 20$'];
        yield 'number with eight unchanged' => ['Strkm 48 und 8 Stunden', 'Strkm 48 und 8 Stunden'];
        yield 'split e-mail joined' => ['an verkehrsrecht@ bh-musterstadt.example', 'an verkehrsrecht@bh-musterstadt.example'];
    }

    #[DataProvider('cases')]
    public function testCorrectsKnownMisreads(string $ocr, string $expected): void
    {
        self::assertSame($expected, (new OcrTextCorrector())->correct($ocr));
    }
}
