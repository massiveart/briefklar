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

    private const string LETTER_03 = "Magistrat der Stadt Musterhausen\nParkraumüberwachung\nRathausplatz 2, 8999 Musterhausen\n\nMusterhausen, 03.11.2026\n\nANONYMVERFÜGUNG\n\nDas Fahrzeug von Lukas Testmann, wohnhaft Gartengasse 5, 8990 Beispieldorf, war am 27.10.2026 um 14:10 Uhr in der Kurzparkzone Rathausplatz ohne gültigen Parkschein abgestellt.\n\nGemäß § 4 Abs. 1 MusterParkG wird eine Geldstrafe von 48,00 Euro festgesetzt. Bitte überweisen Sie den Betrag binnen vier Wochen ab Ausstellung.\n\nBei Fragen wenden Sie sich an parken@musterhausen.example.";

    public function testOnlyPersonalDataOfTheRecipientRemainsFromAModelAnswer(): void
    {
        // The answer qwen3:8b gave for fixture 03 (handover #59).
        $detector = $this->detector('{"entities":['
            .'{"type":"PERSON","value":"Lukas Testmann"},{"type":"ADDRESS","value":"Gartengasse 5"},'
            .'{"type":"ADDRESS","value":"Rathausplatz 2, 8999 Musterhausen"},{"type":"FILE_NUMBER","value":"ANONYMVERFÜGUNG"},'
            .'{"type":"OTHER","value":"27.10.2026"},{"type":"OTHER","value":"14:10"},{"type":"OTHER","value":"MusterParkG"},'
            .'{"type":"EMAIL","value":"parken@musterhausen.example"}]}');

        $entities = $detector->detect(self::LETTER_03);

        self::assertSame(
            [['PERSON', 'Lukas Testmann'], ['ADDRESS', 'Gartengasse 5, 8990 Beispieldorf']],
            array_map(static fn (Entity $e): array => [$e->type->value, $e->value], $entities),
        );
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function findingsInContext(): iterable
    {
        yield 'address extended by postcode and town on the line' => ['{"type":"ADDRESS","value":"Gartengasse 5"}', 'wohnhaft Gartengasse 5, 8990 Beispieldorf, war', ['Gartengasse 5, 8990 Beispieldorf']];
        yield 'address extended by postcode and town on the next line' => ['{"type":"ADDRESS","value":"Lindenweg 12"}', "Frau\nMaria Musterfrau\nLindenweg 12\n6900 Bregenz\n", ["Lindenweg 12\n6900 Bregenz"]];
        yield 'address not extended by a following number' => ['{"type":"ADDRESS","value":"Lindenweg 12"}', 'wohnhaft Lindenweg 12, 2026 umgezogen', ['Lindenweg 12']];
        yield 'date as OTHER dropped' => ['{"type":"OTHER","value":"02.11.2026"}', 'Linz, 02.11.2026', []];
        yield 'time dropped' => ['{"type":"OTHER","value":"14:10 Uhr"}', 'um 14:10 Uhr', []];
        yield 'law after a citation dropped' => ['{"type":"OTHER","value":"MusterParkG"}', 'Gemäß § 4 Abs. 1 MusterParkG wird', []];
        yield 'law after a citation with lit. dropped' => ['{"type":"OTHER","value":"StVO"}', 'gemäß § 99 Abs. 3 lit. a StVO eine', []];
        yield 'same word outside a citation kept' => ['{"type":"PERSON","value":"Muster"}', 'gemäß § 3 StVO. Herr Muster zahlt', ['Muster']];
        yield 'authority e-mail kept' => ['{"type":"EMAIL","value":"parken@musterhausen.example"}', 'Bei Fragen wenden Sie sich an parken@musterhausen.example.', []];
        yield 'private e-mail redacted' => ['{"type":"EMAIL","value":"lukas@example.org"}', 'Ihre Adresse lukas@example.org ist hinterlegt.', ['lukas@example.org']];
        yield 'authority phone kept' => ['{"type":"PHONE","value":"+43 50 233 233"}', 'Bei Fragen erreichen Sie uns unter +43 50 233 233.', []];
        yield 'letterhead address kept' => ['{"type":"ADDRESS","value":"Rathausplatz 2, 8999 Musterhausen"}', "Magistrat der Stadt Musterhausen\nRathausplatz 2, 8999 Musterhausen\n\nFrau\nMaria Musterfrau", []];
        yield 'recipient right under the letterhead stays PII' => ['{"type":"ADDRESS","value":"Gartengasse 5, 8990 Beispieldorf"}', "Magistrat der Stadt Musterhausen\nRathausplatz 2, 8999 Musterhausen\nHerrn\nLukas Testmann\nGartengasse 5, 8990 Beispieldorf\n\nText", ['Gartengasse 5, 8990 Beispieldorf']];
        yield 'recipient address in the first block kept as PII' => ['{"type":"ADDRESS","value":"Lindenweg 12"}', "Frau Maria Musterfrau\nLindenweg 12\n6900 Bregenz", ["Lindenweg 12\n6900 Bregenz"]];
        yield 'file number without digit dropped' => ['{"type":"FILE_NUMBER","value":"ANONYMVERFÜGUNG"}', "\n\nANONYMVERFÜGUNG\n\n", []];
        yield 'file number without keyword dropped' => ['{"type":"FILE_NUMBER","value":"3/2026"}', 'Buchungsmitteilung Nr. 3/2026', []];
        yield 'file number after a keyword kept' => ['{"type":"FILE_NUMBER","value":"BHMS-VK-2026/48213"}', 'Zahl: BHMS-VK-2026/48213', ['BHMS-VK-2026/48213']];
    }

    /**
     * @param list<string> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('findingsInContext')]
    public function testFindingsAreCheckedInTheirContext(string $finding, string $text, array $expected): void
    {
        $entities = $this->detector('{"entities":['.$finding.']}')->detect($text);

        self::assertSame($expected, array_map(static fn (Entity $e): string => $e->value, $entities));
    }

    public function testAuthorityContactsAreRedactedWhenConfigured(): void
    {
        $this->client = new MockModelClient(static fn (): string => '{"entities":[{"type":"EMAIL","value":"parken@musterhausen.example"}]}');
        $platform = new Platform([new Provider('mock', [$this->client], [new MockResultConverter()], new FallbackModelCatalog())], new CatalogBasedModelRouter());

        $entities = (new ModelDetector($platform, 'test-model', keepAuthorityContacts: false))->detect('Bei Fragen wenden Sie sich an parken@musterhausen.example.');

        self::assertCount(1, $entities);
    }

    public function testAnUnusableAnswerIsAskedForOnceMore(): void
    {
        $answers = ['{"entities":[{"type":"PERSON","value":"Jan', '{"entities":[{"type":"PERSON","value":"Jan Novák"}]}'];
        $detector = $this->chunkingDetector(static function () use (&$answers): string {
            return array_shift($answers) ?? '{"entities":[]}';
        }, 1_000);

        $entities = $detector->detect(self::TEXT);

        self::assertCount(2, $this->client->getCalls(), 'the cut answer is requested again');
        self::assertCount(2, $entities);
    }

    public function testAnswersUnusableTwiceFailClosed(): void
    {
        $detector = $this->chunkingDetector(static fn (): string => '{"entities":[{"type":"PERSON"', 1_000);

        try {
            $detector->detect(self::TEXT);
            self::fail('Expected DetectionFailedException');
        } catch (DetectionFailedException) {
        }
        self::assertCount(2, $this->client->getCalls());
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

    public function testAValueAtAnyCutInsideAParagraphIsSentWhole(): void
    {
        $words = array_fill(0, 200, 'Wort');
        for ($position = 0; $position < 200; $position += 7) {
            $text = implode(' ', [...\array_slice($words, 0, $position), 'Maria Musterfrau', ...\array_slice($words, $position)]);
            $detector = $this->chunkingDetector(static fn (): string => '{"entities":[]}', 300);

            $detector->detect($text);

            $sent = $this->sentTexts();
            self::assertGreaterThan(1, \count($sent));
            self::assertNotEmpty(array_filter($sent, static fn (string $chunk): bool => str_contains($chunk, 'Maria Musterfrau')), "name at word {$position} is cut in every chunk");
        }
    }

    public function testTooManyChunksFailBeforeAnythingIsSent(): void
    {
        $detector = $this->chunkingDetector(static fn (): string => '{"entities":[]}', 100);

        try {
            $detector->detect(str_repeat('Wort ', 20 * ModelDetector::MAX_CHUNKS));
            self::fail('Expected DetectionFailedException');
        } catch (DetectionFailedException $e) {
            self::assertStringContainsString('too long', $e->getMessage());
        }
        self::assertCount(0, $this->client->getCalls());
    }

    public function testTextWithoutSpacesStillAdvancesAWholeStepPerChunk(): void
    {
        $detector = $this->chunkingDetector(static fn (): string => '{"entities":[]}', 1_000);
        $text = str_repeat('x', 20_000);

        $start = hrtime(true);
        $detector->detect($text);
        $ms = (hrtime(true) - $start) / 1e6;

        self::assertLessThanOrEqual(30, \count($this->client->getCalls()), 'about 20,000 / 800 chunks, not one per character');
        self::assertLessThan(1_000, $ms);
    }

    public function testTextsFarBeyondTheLimitFailWithoutBeingSplit(): void
    {
        $detector = $this->chunkingDetector(static fn (): string => '{"entities":[]}', 100);

        $start = hrtime(true);
        try {
            $detector->detect(str_repeat('x', 5_000_000));
            self::fail('Expected DetectionFailedException');
        } catch (DetectionFailedException) {
        }

        self::assertLessThan(200, (hrtime(true) - $start) / 1e6);
        self::assertCount(0, $this->client->getCalls());
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
