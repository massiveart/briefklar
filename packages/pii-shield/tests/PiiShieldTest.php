<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Tests;

use Briefklar\PiiShield\Detector\DetectorInterface;
use Briefklar\PiiShield\Entity;
use Briefklar\PiiShield\EntityExtractor;
use Briefklar\PiiShield\EntityType;
use Briefklar\PiiShield\PiiShield;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PiiShieldTest extends TestCase
{
    public function testReplacesEntityWithTypedPlaceholder(): void
    {
        $result = PiiShield::withDefaultDetectors()->redact('SVNR: 4568 150392');

        self::assertSame('SVNR: ⟦SVNR_1⟧', $result->redactedText);
        self::assertSame(['⟦SVNR_1⟧' => '4568 150392'], $result->vault);
    }

    public function testTextWithoutPiiIsUnchanged(): void
    {
        $text = 'Der Betrag von 110,00 Euro ist am 15.11.2026 fällig (§ 49 VStG).';

        $result = PiiShield::withDefaultDetectors()->redact($text);

        self::assertSame($text, $result->redactedText);
        self::assertSame([], $result->vault);
        self::assertSame([], $result->entities);
    }

    public function testNumbersPlaceholdersPerTypeInOrderOfAppearance(): void
    {
        $text = 'IBAN DE89 3704 0044 0532 0130 00 und AT61 1904 3002 3457 3201, SVNR 4568 150392';

        $result = PiiShield::withDefaultDetectors()->redact($text);

        self::assertSame('IBAN ⟦IBAN_1⟧ und ⟦IBAN_2⟧, SVNR ⟦SVNR_1⟧', $result->redactedText);
    }

    public function testSameValueGetsSamePlaceholder(): void
    {
        $text = 'Zahl: BHMS-VK-2026/48213. Verwendungszweck: BHMS-VK-2026/48213';

        $result = PiiShield::withDefaultDetectors()->redact($text);

        self::assertSame('Zahl: ⟦FILE_NUMBER_1⟧. Verwendungszweck: ⟦FILE_NUMBER_1⟧', $result->redactedText);
        self::assertCount(1, $result->vault);
    }

    public function testFurtherOccurrencesOfADetectedValueAreRedacted(): void
    {
        // Second occurrence has no keyword, so no detector finds it on its own.
        $text = "Zahl: BHMS-VK-2026/48213\nBitte BHMS-VK-2026/48213 angeben.";

        $result = PiiShield::withDefaultDetectors()->redact($text);

        self::assertSame("Zahl: ⟦FILE_NUMBER_1⟧\nBitte ⟦FILE_NUMBER_1⟧ angeben.", $result->redactedText);
    }

    public function testValueInsideALongerWordIsNotRedacted(): void
    {
        $text = 'Sehr geehrter Herr Beispiel, siehe Beispielrechnung.';

        $result = PiiShield::withDefaultDetectors()->redact($text);

        self::assertSame('Sehr geehrter Herr ⟦PERSON_1⟧, siehe Beispielrechnung.', $result->redactedText);
    }

    public function testSurnameAloneMapsToTheSamePersonPlaceholder(): void
    {
        $text = "Herrn\nJohann Beispielmann\n\nSehr geehrter Herr Beispielmann, Beispielmann hat bezahlt.";

        $result = PiiShield::withDefaultDetectors()->redact($text);

        self::assertSame("Herrn\n⟦PERSON_1⟧\n\nSehr geehrter Herr ⟦PERSON_1⟧, ⟦PERSON_1⟧ hat bezahlt.", $result->redactedText);
        self::assertSame(['⟦PERSON_1⟧' => 'Johann Beispielmann'], $result->vault);
    }

    public function testSurnameOfAFullNameFromTheAddressBlockIsPropagated(): void
    {
        $text = "Frau\nMaria Musterfrau\n\nMusterfrau wurde verständigt.";

        $result = PiiShield::withDefaultDetectors()->redact($text);

        self::assertSame("Frau\n⟦PERSON_1⟧\n\n⟦PERSON_1⟧ wurde verständigt.", $result->redactedText);
    }

    public function testSurnameOfAnUncertainInlineNameIsNotPropagated(): void
    {
        // The inline heuristic over-redacts "Muster Bescheid"; "Bescheid" must not become a surname everywhere.
        $text = 'Wir haben Herrn Muster Bescheid gegeben. Der Bescheid ist rechtskräftig.';

        $result = PiiShield::withDefaultDetectors()->redact($text);

        self::assertSame('Wir haben Herrn ⟦PERSON_1⟧ gegeben. Der Bescheid ist rechtskräftig.', $result->redactedText);
    }

    public function testAllowlistedValuesAreNeverRedacted(): void
    {
        $shield = new PiiShield(EntityExtractor::defaultDetectors(), allowlist: ['AT61 1904 3002 3457 3201']);

        $result = $shield->redact('IBAN AT61 1904 3002 3457 3201, SVNR 4568 150392');

        self::assertSame('IBAN AT61 1904 3002 3457 3201, SVNR ⟦SVNR_1⟧', $result->redactedText);
    }

    public function testAllowlistAlsoAppliesToPropagatedSurnames(): void
    {
        $shield = new PiiShield(EntityExtractor::defaultDetectors(), allowlist: ['Musterfrau']);

        $result = $shield->redact("Frau\nMaria Musterfrau\n\nMusterfrau wurde verständigt.");

        self::assertSame("Frau\n⟦PERSON_1⟧\n\nMusterfrau wurde verständigt.", $result->redactedText);
    }

    public function testLongerOverlappingEntityWins(): void
    {
        $short = new Entity(EntityType::Phone, '0044 0532 0130', 15, 0.8, 'phone');
        $long = new Entity(EntityType::Iban, 'DE89 3704 0044 0532 0130 00', 5, 1.0, 'iban');
        $shield = new PiiShield([self::fixedDetector($short), self::fixedDetector($long)]);

        $result = $shield->redact('IBAN DE89 3704 0044 0532 0130 00');

        self::assertSame('IBAN ⟦IBAN_1⟧', $result->redactedText);
    }

    public function testOnEqualLengthHigherConfidenceWins(): void
    {
        $weak = new Entity(EntityType::FileNumber, '12345678', 4, 0.5, 'weak');
        $strong = new Entity(EntityType::TaxNumber, '12345678', 4, 0.9, 'strong');
        $shield = new PiiShield([self::fixedDetector($weak), self::fixedDetector($strong)]);

        $result = $shield->redact('Nr. 12345678');

        self::assertSame('Nr. ⟦TAX_NUMBER_1⟧', $result->redactedText);
    }

    public function testEntitiesAreSortedByOffset(): void
    {
        $result = PiiShield::withDefaultDetectors()->redact('SVNR 4568 150392, IBAN AT61 1904 3002 3457 3201');

        self::assertSame(
            [EntityType::AtSvnr, EntityType::Iban],
            array_map(static fn (Entity $e): EntityType => $e->type, $result->entities),
        );
    }

    public function testRestoreReplacesPlaceholdersWithOriginals(): void
    {
        $shield = PiiShield::withDefaultDetectors();
        $redaction = $shield->redact('SVNR 4568 150392, IBAN AT61 1904 3002 3457 3201');

        $restored = $shield->restore('Ihre SVNR ⟦SVNR_1⟧ und Ihr Konto ⟦IBAN_1⟧.', $redaction);

        self::assertSame('Ihre SVNR 4568 150392 und Ihr Konto AT61 1904 3002 3457 3201.', $restored->text);
        self::assertTrue($restored->isClean());
        self::assertSame([], $restored->unusedPlaceholders);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mangledPlaceholderProvider(): iterable
    {
        yield 'exact' => ['⟦SVNR_1⟧'];
        yield 'lowercase' => ['⟦svnr_1⟧'];
        yield 'inner whitespace' => ['⟦ SVNR_1 ⟧'];
        yield 'space instead of underscore' => ['⟦SVNR 1⟧'];
        yield 'markdown-escaped underscore' => ['⟦SVNR\_1⟧'];
        yield 'ascii double brackets' => ['[[SVNR_1]]'];
        yield 'multi-word type' => ['⟦tax number 1⟧'];
    }

    #[DataProvider('mangledPlaceholderProvider')]
    public function testRestoreToleratesMangledPlaceholders(string $placeholder): void
    {
        $shield = PiiShield::withDefaultDetectors();
        $redaction = $shield->redact('SVNR 4568 150392, Steuernummer 46 123/4567');
        $expected = str_contains(strtolower($placeholder), 'tax') ? '46 123/4567' : '4568 150392';

        $restored = $shield->restore("Wert: **{$placeholder}**.", $redaction);

        self::assertSame("Wert: **{$expected}**.", $restored->text);
        self::assertTrue($restored->isClean());
    }

    public function testRestoreReportsUnknownAndUnusedPlaceholders(): void
    {
        $shield = PiiShield::withDefaultDetectors();
        $redaction = $shield->redact('SVNR 4568 150392, IBAN AT61 1904 3002 3457 3201');

        $restored = $shield->restore('Herr ⟦PERSON_1⟧ hat die SVNR ⟦SVNR_1⟧.', $redaction);

        self::assertSame('Herr ⟦PERSON_1⟧ hat die SVNR 4568 150392.', $restored->text);
        self::assertFalse($restored->isClean());
        self::assertSame(['⟦PERSON_1⟧'], $restored->unknownPlaceholders);
        self::assertSame(['⟦IBAN_1⟧'], $restored->unusedPlaceholders);
    }

    private static function fixedDetector(Entity ...$entities): DetectorInterface
    {
        return new class(array_values($entities)) implements DetectorInterface {
            /**
             * @param list<Entity> $entities
             */
            public function __construct(private readonly array $entities)
            {
            }

            public function detect(string $text): array
            {
                return $this->entities;
            }
        };
    }
}
