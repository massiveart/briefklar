<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Tests;

use Briefklar\PiiShield\Detector\DetectorInterface;
use Briefklar\PiiShield\Entity;
use Briefklar\PiiShield\EntityExtractor;
use Briefklar\PiiShield\EntityType;
use PHPUnit\Framework\TestCase;

final class EntityExtractorTest extends TestCase
{
    public function testTouchingSpansAreBothKept(): void
    {
        $extractor = new EntityExtractor([self::detector(
            new Entity(EntityType::FileNumber, 'AB12', 0, 1.0, 'test'),
            new Entity(EntityType::FileNumber, 'CD34', 4, 1.0, 'test'),
        )]);

        self::assertSame(['AB12', 'CD34'], array_map(static fn (Entity $e): string => $e->value, $extractor->extract('AB12CD34')));
    }

    public function testOverlappingSpansKeepTheLongerOne(): void
    {
        $extractor = new EntityExtractor([self::detector(
            new Entity(EntityType::FileNumber, 'AB12', 0, 1.0, 'test'),
            new Entity(EntityType::FileNumber, '12CD34', 2, 0.5, 'test'),
        )]);

        self::assertSame(['12CD34'], array_map(static fn (Entity $e): string => $e->value, $extractor->extract('AB12CD34')));
    }

    public function testEveryDetectedValueIsPropagatedNotJustOnePerType(): void
    {
        $text = "Frau\nMaria Musterfrau\n\nHerrn\nJohann Beispielmann\n\nMusterfrau und Beispielmann wurden verständigt.";

        $values = array_map(static fn (Entity $e): string => $e->value, EntityExtractor::withDefaultDetectors()->extract($text));

        self::assertSame(['Maria Musterfrau', 'Johann Beispielmann', 'Musterfrau', 'Beispielmann'], $values);
    }

    private static function detector(Entity ...$entities): DetectorInterface
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
