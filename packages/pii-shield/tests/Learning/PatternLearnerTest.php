<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Tests\Learning;

use Briefklar\PiiShield\Learning\LearningFailedException;
use Briefklar\PiiShield\Learning\PatternLearner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PatternLearnerTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function learnedPatternProvider(): iterable
    {
        yield 'same shape' => [['AB-1234/56', 'XY-9876/01'], '[A-Z]{2}-\d{4}/\d{2}'];
        yield 'single example generalises letters and digits' => [['VN 1234 5678'], '[A-Z]{2}\s\d{4}\s\d{4}'];
        yield 'constant prefix is kept' => [['KD-12345', 'KD-98765'], 'KD-\d{5}'];
        yield 'varying lengths become ranges' => [['K-123', 'K-12345'], 'K-\d{3,5}'];
        yield 'mixed case letters' => [['Ab12', 'Cd34'], '[A-Z][a-z]\d{2}'];
        yield 'different shapes become alternatives' => [['A123', 'AB-12'], '(?:[A-Z]\d{3}|[A-Z]{2}-\d{2})'];
        yield 'umlauts count as letters' => [['ÄÖ-12', 'XY-34'], '[A-ZÄÖÜ]{2}-\d{2}'];
    }

    /**
     * @param list<string> $examples
     */
    #[DataProvider('learnedPatternProvider')]
    public function testLearnsAPatternThatMatchesAllExamples(array $examples, string $expected): void
    {
        $pattern = (new PatternLearner())->learn($examples);

        self::assertSame($expected, $pattern);
        foreach ($examples as $example) {
            self::assertMatchesRegularExpression('~^'.$pattern.'$~u', $example);
        }
    }

    public function testCounterExamplesThatWouldMatchAreRejected(): void
    {
        $this->expectException(LearningFailedException::class);

        (new PatternLearner())->learn(['AB-1234'], counterExamples: ['XY-9999']);
    }

    public function testCounterExamplesThatDoNotMatchAreFine(): void
    {
        self::assertSame('KD-\d{4}', (new PatternLearner())->learn(['KD-1234', 'KD-5678'], counterExamples: ['XY-9999']));
    }

    public function testNeedsAtLeastOneExample(): void
    {
        $this->expectException(LearningFailedException::class);

        (new PatternLearner())->learn([]);
    }

    public function testRejectsPatternsThatAreTooGeneric(): void
    {
        // A single short word would match half of every letter.
        $this->expectException(LearningFailedException::class);

        (new PatternLearner())->learn(['ab']);
    }
}
