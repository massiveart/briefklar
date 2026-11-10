<?php

declare(strict_types=1);

namespace App\Tests\Integration\Explanation;

use App\Explanation\ExplanationLanguage;
use App\Explanation\ExplanationStyle;
use App\Explanation\LetterExplainer;
use App\Extraction\ExtractedText;
use App\Extraction\ExtractionMethod;
use App\Tests\Support\PdfFixtureFactory;
use App\Tests\Support\ScriptedPlatform;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LetterExplainerTest extends KernelTestCase
{
    public function testExplainsFixture01ThroughTheAgentPipeline(): void
    {
        self::bootKernel();
        $letter = new ExtractedText(PdfFixtureFactory::letter('01-strafverfuegung'), ExtractionMethod::PdfTextLayer);

        $result = self::getContainer()->get(LetterExplainer::class)->explain($letter);

        self::assertSame('Bezirkshauptmannschaft Musterstadt', $result->explanation->sender);
        self::assertTrue($result->explanation->actionRequired);
        self::assertSame('110,00 Euro', $result->explanation->amount);
        self::assertSame(['§ 20 Abs. 2 StVO', '§ 99 Abs. 3 lit. a StVO', '§ 49 VStG'], $result->explanation->legalReferences);
        self::assertNotNull($result->explanation->deadline);
        self::assertStringStartsWith('binnen zwei Wochen ab Zustellung', $result->explanation->deadline);
        self::assertTrue($result->deadlineStatedInLetter);
        self::assertSame(ExtractionMethod::PdfTextLayer, $result->extractionMethod);
        self::assertSame(ExplanationStyle::Standard, $result->style);
    }

    public function testProviderRequestContainsSystemPromptSchemaAndDelimitedLetter(): void
    {
        self::bootKernel();
        $letter = new ExtractedText(PdfFixtureFactory::letter('01-strafverfuegung'), ExtractionMethod::Ocr);

        self::getContainer()->get(LetterExplainer::class)->explain($letter);

        $requests = self::getContainer()->get(ScriptedPlatform::class)->requests();
        self::assertCount(1, $requests);
        self::assertStringContainsString('Do not give legal advice', $requests[0]);
        self::assertStringContainsString('Never invent, compute or estimate deadlines', $requests[0]);
        self::assertStringContainsString('<letter>', $requests[0]);
        self::assertStringContainsString('STRAFVERFÜGUNG', $requests[0]);
    }

    public function testEasyLanguageUsesTheSecondPromptVariant(): void
    {
        self::bootKernel();
        $letter = new ExtractedText(PdfFixtureFactory::letter('01-strafverfuegung'), ExtractionMethod::PdfTextLayer);

        $result = self::getContainer()->get(LetterExplainer::class)->explain($letter, ExplanationStyle::EasyLanguage);

        self::assertSame(ExplanationStyle::EasyLanguage, $result->style);
        $requests = self::getContainer()->get(ScriptedPlatform::class)->requests();
        self::assertCount(1, $requests);
        self::assertStringContainsString('Leichte Sprache', $requests[0]);
        // the safety rules must hold in both variants
        self::assertStringContainsString('Do not give legal advice', $requests[0]);
        self::assertStringContainsString('Never invent, compute or estimate deadlines', $requests[0]);
    }

    public function testStandardVariantDoesNotAskForEasyLanguage(): void
    {
        self::bootKernel();
        $letter = new ExtractedText(PdfFixtureFactory::letter('01-strafverfuegung'), ExtractionMethod::PdfTextLayer);

        self::getContainer()->get(LetterExplainer::class)->explain($letter);

        self::assertStringNotContainsString('Leichte Sprache', self::getContainer()->get(ScriptedPlatform::class)->requests()[0]);
    }

    /**
     * @return iterable<string, array{ExplanationLanguage, ExplanationStyle, string}>
     */
    public static function languages(): iterable
    {
        yield 'german' => [ExplanationLanguage::German, ExplanationStyle::Standard, 'Answer in German.'];
        yield 'english' => [ExplanationLanguage::English, ExplanationStyle::Standard, 'Answer in English.'];
        yield 'english easy read' => [ExplanationLanguage::English, ExplanationStyle::EasyLanguage, 'Answer in English.'];
    }

    #[DataProvider('languages')]
    public function testAnswerLanguageIsSentOutsideTheLetter(ExplanationLanguage $language, ExplanationStyle $style, string $instruction): void
    {
        self::bootKernel();
        $letter = new ExtractedText(PdfFixtureFactory::letter('01-strafverfuegung'), ExtractionMethod::PdfTextLayer);

        $result = self::getContainer()->get(LetterExplainer::class)->explain($letter, $style, $language);

        self::assertSame($language, $result->language);
        $request = self::getContainer()->get(ScriptedPlatform::class)->requests()[0];
        self::assertStringContainsString($instruction, $request);
        // the instruction must not be inside the letter, where the prompt tells the model to ignore instructions
        $closing = mb_strrpos($request, '</letter>');
        self::assertNotFalse($closing);
        self::assertGreaterThan($closing, mb_strpos($request, $instruction));
    }
}
