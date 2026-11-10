<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Tests;

use Briefklar\PiiShield\Entity;
use Briefklar\PiiShield\PiiMasker;
use Briefklar\PiiShield\PiiShield;
use Briefklar\PiiShield\RedactionResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Acceptance tests against the synthetic letters in /fixtures/letters (monorepo only).
 */
final class FixtureLetterTest extends TestCase
{
    private const string FIXTURE_DIR = __DIR__.'/../../../fixtures/letters';

    /**
     * @return iterable<string, array{string, array{pii: list<array{type: string, value: string}>, must_survive: list<string>, negative: list<array{value: string, reason: string}>}}>
     */
    public static function letterProvider(): iterable
    {
        $files = glob(self::FIXTURE_DIR.'/*.txt');
        if (false === $files || [] === $files) {
            yield 'no fixtures' => ['', ['pii' => [], 'must_survive' => [], 'negative' => []]];

            return;
        }

        foreach ($files as $file) {
            $text = (string) file_get_contents($file);
            /** @var array{pii: list<array{type: string, value: string}>, must_survive: list<string>, negative: list<array{value: string, reason: string}>} $expected */
            $expected = json_decode((string) file_get_contents(substr($file, 0, -4).'.expected.json'), true, flags: \JSON_THROW_ON_ERROR);

            yield basename($file, '.txt') => [$text, $expected];
        }
    }

    /**
     * @param array{pii: list<array{type: string, value: string}>, must_survive: list<string>, negative: list<array{value: string, reason: string}>} $expected
     */
    #[DataProvider('letterProvider')]
    public function testEveryExpectedPiiSpanIsRedacted(string $text, array $expected): void
    {
        $this->skipWithoutFixtures($text);
        $result = PiiShield::withDefaultDetectors()->redact($text);

        foreach ($expected['pii'] as $pii) {
            self::assertStringNotContainsString($pii['value'], $result->redactedText, "{$pii['type']} leaked");
            self::assertTrue(
                $this->hasEntity($result, $pii['type'], $pii['value']),
                "No {$pii['type']} entity for \"{$pii['value']}\"",
            );
        }
    }

    /**
     * @param array{pii: list<array{type: string, value: string}>, must_survive: list<string>, negative: list<array{value: string, reason: string}>} $expected
     */
    #[DataProvider('letterProvider')]
    public function testMustSurviveStringsAreKept(string $text, array $expected): void
    {
        $this->skipWithoutFixtures($text);
        $result = PiiShield::withDefaultDetectors()->redact($text);

        foreach ($expected['must_survive'] as $keep) {
            self::assertStringContainsString($keep, $result->redactedText);
        }
    }

    /**
     * @param array{pii: list<array{type: string, value: string}>, must_survive: list<string>, negative: list<array{value: string, reason: string}>} $expected
     */
    #[DataProvider('letterProvider')]
    public function testNegativeSamplesAreNotRedacted(string $text, array $expected): void
    {
        $this->skipWithoutFixtures($text);
        if ([] === $expected['negative']) {
            $this->expectNotToPerformAssertions();
        }

        foreach ($expected['negative'] as $negative) {
            $result = PiiShield::withDefaultDetectors()->redact($negative['value']);
            self::assertSame([], $result->entities, $negative['reason']);
        }
    }

    /**
     * Restore yields the original text. The only accepted difference: a surname alias
     * ("Herr Beispielmann") is restored as the full name it was linked to.
     *
     * @param array{pii: list<array{type: string, value: string}>, must_survive: list<string>, negative: list<array{value: string, reason: string}>} $expected
     */
    #[DataProvider('letterProvider')]
    public function testRestoreRoundTrip(string $text, array $expected): void
    {
        $this->skipWithoutFixtures($text);
        $shield = PiiShield::withDefaultDetectors();
        $redaction = $shield->redact($text);

        $restored = $shield->restore($redaction->redactedText, $redaction);

        self::assertSame($this->withAliasesExpanded($text, $redaction), $restored->text);
        self::assertTrue($restored->isClean());
        self::assertSame([], $restored->unusedPlaceholders);
    }

    /**
     * @param array{pii: list<array{type: string, value: string}>, must_survive: list<string>, negative: list<array{value: string, reason: string}>} $expected
     */
    #[DataProvider('letterProvider')]
    public function testMaskingRemovesPiiAndKeepsMustSurvive(string $text, array $expected): void
    {
        $this->skipWithoutFixtures($text);

        $masked = PiiMasker::withDefaultDetectors()->mask($text);

        foreach ($expected['pii'] as $pii) {
            self::assertStringNotContainsString($pii['value'], $masked, "{$pii['type']} leaked");
        }
        foreach ($expected['must_survive'] as $keep) {
            self::assertStringContainsString($keep, $masked);
        }
    }

    private function skipWithoutFixtures(string $text): void
    {
        if ('' === $text) {
            self::markTestSkipped('Fixture letters are only available inside the briefklar monorepo.');
        }
    }

    private function hasEntity(RedactionResult $result, string $type, string $value): bool
    {
        return [] !== array_filter(
            $result->entities,
            static fn (Entity $e): bool => $e->type->value === $type && $e->value === $value,
        );
    }

    /**
     * An alias is an entity value that is not in the vault; it restores as the full name ending in it.
     */
    private function withAliasesExpanded(string $text, RedactionResult $redaction): string
    {
        $expanded = '';
        $cursor = 0;
        foreach ($redaction->entities as $entity) {
            $restoredValue = $entity->value;
            if (!\in_array($entity->value, $redaction->vault, true)) {
                $fullNames = array_filter($redaction->vault, static fn (string $v): bool => str_ends_with($v, ' '.$entity->value));
                self::assertCount(1, $fullNames, "Alias \"{$entity->value}\" must link to exactly one full name");
                $restoredValue = reset($fullNames);
            }
            $expanded .= mb_substr($text, $cursor, $entity->offset - $cursor).$restoredValue;
            $cursor = $entity->offset + $entity->length();
        }

        return $expanded.mb_substr($text, $cursor);
    }
}
