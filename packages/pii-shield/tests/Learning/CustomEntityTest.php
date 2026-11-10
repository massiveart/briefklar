<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Tests\Learning;

use Briefklar\PiiShield\Entity;
use Briefklar\PiiShield\EntityExtractor;
use Briefklar\PiiShield\EntityType;
use Briefklar\PiiShield\Learning\CustomEntityDetector;
use Briefklar\PiiShield\Learning\CustomEntityType;
use Briefklar\PiiShield\Learning\LearningFailedException;
use Briefklar\PiiShield\PiiMasker;
use Briefklar\PiiShield\PiiShield;
use PHPUnit\Framework\TestCase;

final class CustomEntityTest extends TestCase
{
    private const string TEXT = 'Ihre Versichertennummer VN-2026-000417 (Polizze VN-2025-998812) ist gültig. Siehe VN-2026-000417.';

    private static function insuranceNumber(): CustomEntityType
    {
        return new CustomEntityType('INSURANCE_NUMBER', 'Versichertennummer einer Versicherung', ['VN-2026-000417'], [], 'VN-\d{4}-\d{6}');
    }

    public function testDetectorFindsAllMatchesWithItsLabel(): void
    {
        $entities = (new CustomEntityDetector(self::insuranceNumber()))->detect(self::TEXT);

        self::assertSame(['VN-2026-000417', 'VN-2025-998812', 'VN-2026-000417'], array_map(static fn (Entity $e): string => $e->value, $entities));
        self::assertSame(EntityType::Other, $entities[0]->type);
        self::assertSame('INSURANCE_NUMBER', $entities[0]->label);
        self::assertSame('INSURANCE_NUMBER', $entities[0]->typeName());
    }

    public function testDetectorRespectsWordBoundaries(): void
    {
        self::assertSame([], (new CustomEntityDetector(self::insuranceNumber()))->detect('XVN-2026-0004171'));
    }

    public function testPlaceholdersCarryTheCustomName(): void
    {
        $shield = new PiiShield([...EntityExtractor::defaultDetectors(), new CustomEntityDetector(self::insuranceNumber())]);

        $redaction = $shield->redact(self::TEXT);

        self::assertSame('Ihre Versichertennummer ⟦INSURANCE_NUMBER_1⟧ (Polizze ⟦INSURANCE_NUMBER_2⟧) ist gültig. Siehe ⟦INSURANCE_NUMBER_1⟧.', $redaction->redactedText);
        self::assertSame(self::TEXT, $shield->restore($redaction->redactedText, $redaction)->text);
        self::assertTrue($shield->restore('Nummer ⟦insurance number 2⟧', $redaction)->isClean());
    }

    public function testMaskingUsesTheCustomName(): void
    {
        $masker = new PiiMasker([new CustomEntityDetector(self::insuranceNumber())]);

        self::assertSame('Nummer [INSURANCE_NUMBER].', $masker->mask('Nummer VN-2026-000417.'));
    }

    public function testDefinitionNeedsAValidName(): void
    {
        $this->expectException(LearningFailedException::class);

        new CustomEntityType('versicherung nr', 'x', ['VN-2026-000417'], [], 'VN-\d{4}-\d{6}');
    }

    public function testDefinitionMustNotShadowABuiltInType(): void
    {
        $this->expectException(LearningFailedException::class);

        new CustomEntityType('IBAN', 'x', ['VN-2026-000417'], [], 'VN-\d{4}-\d{6}');
    }

    public function testPatternMustMatchAllExamples(): void
    {
        $this->expectException(LearningFailedException::class);

        new CustomEntityType('INSURANCE_NUMBER', 'x', ['VN-2026-000417', 'AB-1'], [], 'VN-\d{4}-\d{6}');
    }

    public function testPatternMustNotMatchCounterExamples(): void
    {
        $this->expectException(LearningFailedException::class);

        new CustomEntityType('INSURANCE_NUMBER', 'x', ['VN-2026-000417'], ['VN-2026-999999'], 'VN-\d{4}-\d{6}');
    }

    public function testInvalidPatternIsRejected(): void
    {
        $this->expectException(LearningFailedException::class);

        new CustomEntityType('INSURANCE_NUMBER', 'x', ['VN-2026-000417'], [], 'VN-(\d{4}');
    }

    public function testModelOnlyTypesHaveNoPattern(): void
    {
        $type = new CustomEntityType('PET_NAME', 'Name eines Haustiers', ['Bello', 'Minka']);

        self::assertNull($type->pattern);
        self::assertSame([], (new CustomEntityDetector($type))->detect('Bello bellt.'));
    }

    public function testRoundTripsThroughArrays(): void
    {
        $type = self::insuranceNumber();

        self::assertEquals($type, CustomEntityType::fromArray($type->toArray()));
    }
}
