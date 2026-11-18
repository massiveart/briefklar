<?php

declare(strict_types=1);

namespace App\Tests\Unit\Explanation;

use App\Explanation\ExplanationTimeLimit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExplanationTimeLimitTest extends TestCase
{
    /**
     * @return iterable<string, array{bool, positive-int, int}>
     */
    public static function limits(): iterable
    {
        // EXPLANATION_TIMEOUT 120 s per model call, plus 60 s for OCR.
        yield 'explanation only' => [false, 1, 180];
        yield 'no detection, so passes do not matter' => [false, 2, 180];
        yield 'detection once (web upload, explain_letter)' => [true, 1, 300];
        yield 'detection twice (explain_letter_file)' => [true, 2, 420];
    }

    /**
     * @param positive-int $redactionPasses
     */
    #[DataProvider('limits')]
    public function testOneTimeoutPerModelCall(bool $modelDetection, int $redactionPasses, int $expected): void
    {
        self::assertSame($expected, (new ExplanationTimeLimit(120, $modelDetection))->seconds($redactionPasses));
    }
}
