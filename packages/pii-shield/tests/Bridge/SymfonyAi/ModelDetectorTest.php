<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Tests\Bridge\SymfonyAi;

use Briefklar\PiiShield\Bridge\SymfonyAi\DetectionFailedException;
use Briefklar\PiiShield\Bridge\SymfonyAi\ModelDetector;
use Briefklar\PiiShield\Bridge\SymfonyAi\ModelLocation;
use Briefklar\PiiShield\Entity;
use Briefklar\PiiShield\EntityType;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\ModelCatalog\FallbackModelCatalog;
use Symfony\AI\Platform\ModelRouter\CatalogBasedModelRouter;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\Test\MockModelClient;
use Symfony\AI\Platform\Test\MockResultConverter;

final class ModelDetectorTest extends TestCase
{
    private const string TEXT = "Vážený pan Jan Novák,\nčíslo pasu CZ1234567, Lindenweg 12\n6900 Bregenz. Jan Novák zahlt 110,00 Euro.";

    private MockModelClient $client;

    public function testFindingsAreGroundedInTheTextWithOffsets(): void
    {
        $detector = $this->detector('{"entities":[{"type":"PERSON","value":"Jan Novák"},{"type":"ID_NUMBER","value":"CZ1234567"}]}');

        $entities = $detector->detect(self::TEXT);

        self::assertSame(
            [['PERSON', 'Jan Novák'], ['ID_NUMBER', 'CZ1234567'], ['PERSON', 'Jan Novák']],
            array_map(static fn (Entity $e): array => [$e->type->value, $e->value], $entities),
        );
        foreach ($entities as $entity) {
            self::assertSame($entity->value, mb_substr(self::TEXT, $entity->offset, $entity->length()));
            self::assertSame('model', $entity->detector);
        }
    }

    public function testValuesWithDifferentWhitespaceOrCommasAreFound(): void
    {
        $detector = $this->detector('{"entities":[{"type":"ADDRESS","value":"Lindenweg 12, 6900 Bregenz"}]}');

        $entities = $detector->detect(self::TEXT);

        self::assertCount(1, $entities);
        self::assertSame("Lindenweg 12\n6900 Bregenz", $entities[0]->value);
    }

    public function testInventedValuesAreDropped(): void
    {
        $detector = $this->detector('{"entities":[{"type":"PERSON","value":"Maria Musterfrau"}]}');

        self::assertSame([], $detector->detect(self::TEXT));
    }

    public function testValuesInsideLongerWordsAreNotMatched(): void
    {
        $detector = $this->detector('{"entities":[{"type":"PERSON","value":"Jan"}]}');

        self::assertSame([], $detector->detect('Januar und Jana'));
    }

    public function testUnknownTypesAreMappedToKnownOnes(): void
    {
        $detector = $this->detector('{"entities":[{"type":"passport","value":"CZ1234567"},{"type":"favourite colour","value":"Bregenz"}]}');

        $types = array_map(static fn (Entity $e): EntityType => $e->type, $detector->detect(self::TEXT));

        self::assertSame([EntityType::IdNumber, EntityType::Other], $types);
    }

    public function testToleratesCodeFencesAndProseAroundTheJson(): void
    {
        $detector = $this->detector("Hier die Funde:\n```json\n{\"entities\":[{\"type\":\"PERSON\",\"value\":\"Jan Novák\"}]}\n```");

        self::assertCount(2, $detector->detect(self::TEXT));
    }

    public function testIgnoresTheReasoningOfThinkingModels(): void
    {
        $detector = $this->detector("<think>The user wants {entities}. {\"entities\":[{\"type\":\"PERSON\",\"value\":\"Maria\"}]} maybe?</think>\n{\"entities\":[{\"type\":\"ID_NUMBER\",\"value\":\"CZ1234567\"}]}");

        $types = array_map(static fn (Entity $e): EntityType => $e->type, $detector->detect(self::TEXT));

        self::assertSame([EntityType::IdNumber], $types);
    }

    public function testInvalidResponseFailsClosed(): void
    {
        $detector = $this->detector('Ich kann dabei nicht helfen.');

        $this->expectException(DetectionFailedException::class);

        $detector->detect(self::TEXT);
    }

    public function testSendsTheTextToTheConfiguredModelWithInstructions(): void
    {
        $this->detector('{"entities":[]}', model: 'llama3.1:8b')->detect(self::TEXT);

        $call = $this->client->getCalls()[0];
        self::assertSame('llama3.1:8b', $call['model']->getName());
        $payload = json_encode($call['payload'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('Jan Novák', $payload);
        self::assertStringContainsString('ID_NUMBER', $payload, 'the prompt lists the entity types');
    }

    public function testEmptyTextIsNotSent(): void
    {
        self::assertSame([], $this->detector('{"entities":[]}')->detect("  \n"));
        self::assertSame([], $this->client->getCalls());
    }

    public function testExternalProvidersNeedAnExplicitAcknowledgement(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->detector('{"entities":[]}', location: ModelLocation::External);
    }

    public function testExternalProvidersWorkWhenAcknowledged(): void
    {
        $detector = $this->detector('{"entities":[]}', location: ModelLocation::External, acknowledgeExternal: true);

        self::assertSame([], $detector->detect(self::TEXT));
    }

    private function detector(string $response, string $model = 'test-model', ModelLocation $location = ModelLocation::Local, bool $acknowledgeExternal = false): ModelDetector
    {
        $this->client = new MockModelClient(static fn (): string => $response);
        $platform = new Platform(
            [new Provider('mock', [$this->client], [new MockResultConverter()], new FallbackModelCatalog())],
            new CatalogBasedModelRouter(),
        );

        return new ModelDetector($platform, $model, $location, $acknowledgeExternal);
    }
}
