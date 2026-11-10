<?php

declare(strict_types=1);

namespace App\Tests\Unit\Extraction;

use App\Extraction\Ocr\OcrTextCorrector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OcrTextCorrectorTest extends TestCase
{
    /**
     * Misreads observed with Tesseract 5 (deu) on fixture 01. The output depends on the
     * Tesseract build and the CPU (ARM container vs. x86 CI runner), so all variants are covered.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        // Tesseract 5.5, arm64 (app container on Apple Silicon)
        yield 'dollar instead of paragraph' => ['gemäß $ 99 Abs. 3', 'gemäß § 99 Abs. 3'];
        yield 'eight-dollar instead of paragraph' => ['verletzt: 8$ 20 Abs. 2 StVO', 'verletzt: § 20 Abs. 2 StVO'];
        yield 'dollar in parentheses' => ['erheben ($ 49 VStG).', 'erheben (§ 49 VStG).'];
        yield 'double paragraph' => ['$$ 1 bis 3', '§§ 1 bis 3'];
        yield 'missing space' => ['$49 VStG', '§ 49 VStG'];

        // Tesseract 5.3, x86_64 (GitHub Actions ubuntu-latest): "§" read as a single digit
        yield 'eight before Abs.' => ['verletzt: 8 20 Abs. 2 StVO', 'verletzt: § 20 Abs. 2 StVO'];
        yield 'five before Abs.' => ['gemäß 5 99 Abs. 3 lit. a StVO', 'gemäß § 99 Abs. 3 lit. a StVO'];
        yield 'eight before law' => ['gemäß 8 99 Abs. 3', 'gemäß § 99 Abs. 3'];
        yield 'three in parentheses before law' => ['erheben (3 49 VStG).', 'erheben (§ 49 VStG).'];
        yield 'eight in parentheses before law' => ['erheben (8 49 VStG).', 'erheben (§ 49 VStG).'];
        yield 'S before law' => ['nach S 14 AVG', 'nach § 14 AVG'];
        yield 'law with hyphen' => ['Art. 5 und 8 7 B-VG', 'Art. 5 und § 7 B-VG'];

        // must stay unchanged
        yield 'correct paragraph unchanged' => ['§ 49 VStG', '§ 49 VStG'];
        yield 'dollar amount unchanged' => ['USD 20$', 'USD 20$'];
        yield 'number with eight unchanged' => ['Strkm 48 und 8 Stunden', 'Strkm 48 und 8 Stunden'];
        yield 'time unchanged' => ['ab 8 20 Uhr', 'ab 8 20 Uhr'];
        yield 'count before amount unchanged' => ['in 3 20 Euro Raten', 'in 3 20 Euro Raten'];
        yield 'list of numbers unchanged' => ['Zimmer 3 49 und 5', 'Zimmer 3 49 und 5'];
        yield 'digit glued to number unchanged' => ['Kennzeichen B-123AB 5 km/h', 'Kennzeichen B-123AB 5 km/h'];
        yield 'multi-digit number before law unchanged' => ['Fassung 2026 49 VStG', 'Fassung 2026 49 VStG'];

        yield 'split e-mail joined' => ['an verkehrsrecht@ bh-musterstadt.example', 'an verkehrsrecht@bh-musterstadt.example'];
    }

    #[DataProvider('cases')]
    public function testCorrectsKnownMisreads(string $ocr, string $expected): void
    {
        self::assertSame($expected, (new OcrTextCorrector())->correct($ocr));
    }
}
