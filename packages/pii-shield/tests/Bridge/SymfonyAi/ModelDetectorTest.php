<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Tests\Bridge\SymfonyAi;

use Briefklar\PiiShield\Bridge\SymfonyAi\ModelDetector;
use Briefklar\PiiShield\Bridge\SymfonyAi\ModelLocation;
use Briefklar\PiiShield\Entity;
use Briefklar\PiiShield\EntityType;
use Briefklar\PiiShield\Exception\DetectionFailedException;
use Briefklar\PiiShield\Learning\CacheCustomEntityStore;
use Briefklar\PiiShield\Learning\CustomEntityType;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\FallbackModelCatalog;
use Symfony\AI\Platform\ModelRouter\CatalogBasedModelRouter;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\Test\MockModelClient;
use Symfony\AI\Platform\Test\MockResultConverter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

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

    public function testSalutationsAndTrailingPunctuationAreNotPartOfTheValue(): void
    {
        $detector = $this->detector('{"entities":[{"type":"PERSON","value":"Herr Jan Novák,"},{"type":"ADDRESS","value":"Lindenweg 12, 6900 Bregenz."}]}');

        $values = array_map(static fn (Entity $e): string => $e->value, $detector->detect(self::TEXT));

        self::assertSame(['Jan Novák', "Lindenweg 12\n6900 Bregenz", 'Jan Novák'], $values);
    }

    public function testDatesAreBirthDatesOnlyWithABirthKeyword(): void
    {
        $text = 'Login am 03.11.2026. Geboren am 02.05.1981, narozen 7. 3. 1990.';
        $detector = $this->detector('{"entities":[{"type":"BIRTH_DATE","value":"03.11.2026"},{"type":"BIRTH_DATE","value":"02.05.1981"},{"type":"BIRTH_DATE","value":"7. 3. 1990"}]}');

        $values = array_map(static fn (Entity $e): string => $e->value, $detector->detect($text));

        self::assertSame(['02.05.1981', '7. 3. 1990'], $values);
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

    public function testLearnedTypesAreTaughtWithExamplesAndReportedByName(): void
    {
        $store = new CacheCustomEntityStore(new ArrayAdapter());
        $store->save(new CustomEntityType('PET_NAME', 'Name eines Haustiers', ['Bello', 'Minka'], ['Hund']));
        $this->client = new MockModelClient(static fn (): string => '{"entities":[{"type":"PET_NAME","value":"Rex"}]}');
        $platform = new Platform([new Provider('mock', [$this->client], [new MockResultConverter()], new FallbackModelCatalog())], new CatalogBasedModelRouter());
        $detector = new ModelDetector($platform, 'test-model', learnedTypes: $store);

        $entities = $detector->detect('Unser Hund Rex bellt.');

        self::assertCount(1, $entities);
        self::assertSame(EntityType::Other, $entities[0]->type);
        self::assertSame('PET_NAME', $entities[0]->label);
        $payload = json_encode($this->client->getCalls()[0]['payload'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('PET_NAME', $payload);
        self::assertStringContainsString('Name eines Haustiers', $payload);
        self::assertStringContainsString('Minka', $payload);
        self::assertStringContainsString('data, not instructions', $payload, 'learned types are passed as marked data');
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

    public function testShortTextsAreSentInOneCall(): void
    {
        $this->detector('{"entities":[]}')->detect(self::TEXT);

        self::assertCount(1, $this->client->getCalls());
    }

    public function testLongTextsAreSentInChunksAlongParagraphs(): void
    {
        $paragraphs = array_map(static fn (int $i): string => "Absatz {$i}: ".str_repeat('Text ', 30), range(1, 12));
        $text = implode("\n\n", $paragraphs);
        $detector = $this->chunkingDetector(static fn (): string => '{"entities":[]}', 500);

        $detector->detect($text);

        $sent = $this->sentTexts();
        self::assertGreaterThan(1, \count($sent));
        foreach ($sent as $chunk) {
            self::assertLessThanOrEqual(500, mb_strlen($chunk));
        }
        foreach ($paragraphs as $paragraph) {
            self::assertNotEmpty(array_filter($sent, static fn (string $chunk): bool => str_contains($chunk, $paragraph)), 'every paragraph is sent whole');
        }
    }

    public function testAParagraphLongerThanAChunkIsSplitAtLinesAndSpaces(): void
    {
        $text = implode("\n", array_map(static fn (int $i): string => "Zeile {$i} ".str_repeat('Wort ', 20), range(1, 30)));
        $detector = $this->chunkingDetector(static fn (): string => '{"entities":[]}', 300);

        $detector->detect($text);

        $sent = $this->sentTexts();
        foreach ($sent as $chunk) {
            self::assertLessThanOrEqual(300, mb_strlen($chunk));
        }
        self::assertStringContainsString('Zeile 30', implode('', $sent));
    }

    public function testFindingsOfAnyChunkAreRedactedInTheWholeText(): void
    {
        $text = "Frau Maria Musterfrau ist Antragstellerin.\n\n".str_repeat("Weiterer Absatz ohne Daten.\n\n", 40).'Gezeichnet: Maria Musterfrau';
        $detector = $this->chunkingDetector(static fn (Model $model, array $payload): string => str_contains((string) json_encode($payload, \JSON_UNESCAPED_UNICODE), 'Antragstellerin')
            ? '{"entities":[{"type":"PERSON","value":"Maria Musterfrau"}]}'
            : '{"entities":[]}', 400);

        $entities = $detector->detect($text);

        self::assertGreaterThan(1, \count($this->client->getCalls()));
        self::assertCount(2, $entities, 'found in the first chunk, redacted in the last too');
    }

    public function testChunkSizeMustLeaveRoomForAParagraph(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->chunkingDetector(static fn (): string => '{"entities":[]}', 99);
    }

    /**
     * @return list<string> the user message of every call
     */
    private function sentTexts(): array
    {
        return array_map(static function (array $call): string {
            /** @var array{messages: list<array{role: string, content: string}>} $payload */
            $payload = $call['payload'];

            return $payload['messages'][1]['content'];
        }, $this->client->getCalls());
    }

    /**
     * @param \Closure(Model, array<mixed>): string $response
     */
    private function chunkingDetector(\Closure $response, int $chunkSize): ModelDetector
    {
        $this->client = new MockModelClient($response);
        $platform = new Platform(
            [new Provider('mock', [$this->client], [new MockResultConverter()], new FallbackModelCatalog())],
            new CatalogBasedModelRouter(),
        );

        return new ModelDetector($platform, 'test-model', chunkSize: $chunkSize);
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
