<?php

declare(strict_types=1);

namespace App\Tests\Unit\Explanation;

use App\Explanation\Evaluation\ExplanationRules as R;
use App\Explanation\ExplainedLetter;
use App\Explanation\ExplanationLanguage;
use App\Explanation\ExplanationStyle;
use App\Explanation\LetterExplanation;
use App\Extraction\ExtractionMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExplanationRulesTest extends TestCase
{
    private const string LETTER = "Magistrat der Stadt Beispielstadt\nReferat Parkraum\n\nDer Betrag von 36,00 Euro ist binnen vier Wochen ab Ausstellung zu bezahlen (§ 7 Abs. 1 BspG).";

    /**
     * Rules as observed on real model answers (qwen3:8b, 11.11.2026): each broken case is a mistake the model made.
     *
     * @return iterable<string, array{array<string, mixed>, string, bool|null}>
     */
    public static function cases(): iterable
    {
        yield 'sender copied' => [[], R::SENDER_FROM_LETTER, true];
        yield 'sender with department' => [['sender' => 'Magistrat der Stadt Beispielstadt, Referat Parkraum'], R::SENDER_FROM_LETTER, false];
        yield 'sender translated' => [['sender' => 'Magistrat der Stadt Beispielstadt (City Council)'], R::SENDER_FROM_LETTER, false];

        yield 'amount copied' => [[], R::AMOUNT_VERBATIM, true];
        yield 'no amount' => [['amount' => null], R::AMOUNT_VERBATIM, true];
        yield 'amount shortened' => [['amount' => '36 Euro'], R::AMOUNT_VERBATIM, false];
        yield 'amount reformatted' => [['amount' => 'EUR 36.00'], R::AMOUNT_VERBATIM, false];

        yield 'reference copied' => [[], R::REFERENCES_VERBATIM, true];
        yield 'reference with explanation' => [['legalReferences' => ['§ 7 Abs. 1 BspG (parking fee)']], R::REFERENCES_VERBATIM, false];

        yield 'deadline verified' => [[], R::DEADLINE_VERBATIM, true];
        yield 'deadline not verified' => [['deadlineStated' => false], R::DEADLINE_VERBATIM, false];

        yield 'placeholders restored' => [[], R::PLACEHOLDERS_RESTORED, true];
        yield 'placeholder left in prose' => [['summary' => 'Zahle auf das Konto ⟦IBAN_7⟧.'], R::PLACEHOLDERS_RESTORED, false];
        yield 'placeholder invented' => [['invented' => ['⟦IBAN_7⟧']], R::PLACEHOLDERS_RESTORED, false];

        yield 'du' => [[], R::INFORMAL_ADDRESS, true];
        yield 'Sie' => [['summary' => 'Sie müssen 36,00 Euro bezahlen.'], R::INFORMAL_ADDRESS, false];
        yield 'Ihnen in a question' => [['questions' => ['Was passiert, wenn Ihnen das Geld fehlt?']], R::INFORMAL_ADDRESS, false];
        yield 'sie (they) lower case' => [['summary' => 'Die Stadt sagt, sie will 36,00 Euro.'], R::INFORMAL_ADDRESS, true];
        yield 'formal address not checked in English' => [['language' => ExplanationLanguage::English, 'summary' => 'Sie means you.'], R::INFORMAL_ADDRESS, null];

        yield 'middle dots only for easy German' => [[], R::MIDDLE_DOTS, null];
        yield 'easy German with middle dot' => [['style' => ExplanationStyle::EasyLanguage, 'summary' => 'Du musst die Park·gebühr zahlen.'], R::MIDDLE_DOTS, true];
        yield 'easy German without middle dot' => [['style' => ExplanationStyle::EasyLanguage], R::MIDDLE_DOTS, false];
    }

    /**
     * @param array<string, mixed> $changes
     */
    #[DataProvider('cases')]
    public function testRule(array $changes, string $rule, ?bool $expected): void
    {
        self::assertSame($expected, (new R())->check(self::explained($changes), self::LETTER)[$rule]);
    }

    /**
     * @param array<string, mixed> $c
     */
    private static function explained(array $c): ExplainedLetter
    {
        /** @var list<string> $references */
        $references = $c['legalReferences'] ?? ['§ 7 Abs. 1 BspG'];
        /** @var list<string> $questions */
        $questions = $c['questions'] ?? ['Bis wann gilt die Ausstellung?'];
        /** @var list<string> $invented */
        $invented = $c['invented'] ?? [];
        $amount = \array_key_exists('amount', $c) ? $c['amount'] : '36,00 Euro';
        \assert(null === $amount || \is_string($amount));
        $sender = $c['sender'] ?? 'Magistrat der Stadt Beispielstadt';
        $summary = $c['summary'] ?? 'Du musst eine Parkgebühr zahlen.';
        \assert(\is_string($sender) && \is_string($summary));
        $style = $c['style'] ?? ExplanationStyle::Standard;
        $language = $c['language'] ?? ExplanationLanguage::German;
        \assert($style instanceof ExplanationStyle && $language instanceof ExplanationLanguage);

        $explanation = new LetterExplanation(
            $sender,
            'Du sollst eine Parkgebühr zahlen.',
            $summary,
            true,
            ['Bezahle 36,00 Euro.'],
            'binnen vier Wochen ab Ausstellung – Der Betrag von 36,00 Euro ist binnen vier Wochen ab Ausstellung zu bezahlen (§ 7 Abs. 1 BspG).',
            $amount,
            $references,
            $questions,
        );

        return new ExplainedLetter($explanation, (bool) ($c['deadlineStated'] ?? true), ExtractionMethod::PdfTextLayer, $style, $language, $invented);
    }
}
