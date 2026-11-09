<?php

declare(strict_types=1);

namespace App\Extraction\Ocr;

/**
 * Fixes systematic Tesseract misreads that matter for the explanation.
 *
 * Kept deliberately narrow: every rule is backed by a test case in OcrTextCorrectorTest.
 */
final readonly class OcrTextCorrector
{
    public function correct(string $text): string
    {
        // "§" is read as "$" or "8$". Only before a number, so dollar amounts stay untouched.
        $text = (string) preg_replace_callback(
            '/(?<![\w$])8?(\$+)(?=\s?\d)/u',
            static fn (array $m): string => str_repeat('§', \strlen($m[1])),
            $text,
        );
        $text = (string) preg_replace('/(§+)\s*(?=\d)/u', '$1 ', $text);

        // On some builds/CPUs "§" is read as a lone "8", "5", "3" or "S". Digits are ambiguous, so this
        // only applies when a legal citation follows: "<number> Abs." or "<number> <law acronym>"
        // (an acronym has at least two capitals: StVO, VStG, AVG, B-VG).
        $text = (string) preg_replace(
            '/(?<![\p{L}\p{N}§$.,\/-])[358S](?= \d{1,4}[a-z]? (?:Abs\.|\p{Lu}[\p{L}-]*\p{Lu}\p{L}*\b))/u',
            '§',
            $text,
        );

        // A space is often inserted after "@" in e-mail addresses.
        return (string) preg_replace('/(\S@)\s+(?=[\w.-]+\.[a-z]{2,})/iu', '$1', $text);
    }
}
