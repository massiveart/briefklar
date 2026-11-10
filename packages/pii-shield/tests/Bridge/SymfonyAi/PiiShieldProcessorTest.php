<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Tests\Bridge\SymfonyAi;

use Briefklar\PiiShield\Bridge\SymfonyAi\PiiShieldProcessor;
use Briefklar\PiiShield\Exception\UnsupportedContentException;
use Briefklar\PiiShield\PiiShield;
use Briefklar\PiiShield\Tests\Bridge\SymfonyAi\Fixtures\Explanation;
use Briefklar\PiiShield\Tests\Bridge\SymfonyAi\Fixtures\Payment;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\InputProcessorInterface;
use Symfony\AI\Agent\OutputProcessorInterface;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\ModelCatalog\FallbackModelCatalog;
use Symfony\AI\Platform\ModelRouter\CatalogBasedModelRouter;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Test\MockModelClient;
use Symfony\AI\Platform\Test\MockResultConverter;

final class PiiShieldProcessorTest extends TestCase
{
    private const string LETTER = "Herrn\nJohann Beispielmann\nBahnhofstraße 7/3\n4020 Linz\n\nSVNR 4568 150392\nIBAN AT61 1904 3002 3457 3201\nBetrag 1.245,00 Euro, fällig am 15.11.2026.";

    private const array ORIGINALS = ['Johann Beispielmann', 'Bahnhofstraße 7/3', '4568 150392', 'AT61 1904 3002 3457 3201'];

    private MockModelClient $client;

    public function testNoPersonalDataReachesThePlatform(): void
    {
        $agent = $this->agent(static fn (): string => 'ok');

        $agent->call(new MessageBag(Message::forSystem('Erkläre den Brief.'), Message::ofUser(self::LETTER)))->getResult();

        $payload = $this->sentPayload();
        foreach (self::ORIGINALS as $original) {
            self::assertStringNotContainsString($original, $payload);
        }
        self::assertStringContainsString('⟦PERSON_1⟧', $payload);
        self::assertStringContainsString('⟦SVNR_1⟧', $payload);
        self::assertStringContainsString('1.245,00 Euro', $payload, 'amounts must survive');
        self::assertStringContainsString('15.11.2026', $payload, 'deadlines must survive');
    }

    public function testTextAnswerIsRestored(): void
    {
        $agent = $this->agent(static fn (): string => '⟦PERSON_1⟧, bitte überweisen Sie auf ⟦IBAN_1⟧.');

        $result = $agent->call(new MessageBag(Message::ofUser(self::LETTER)))->getResult();

        self::assertSame('Johann Beispielmann, bitte überweisen Sie auf AT61 1904 3002 3457 3201.', $result->getContent());
    }

    public function testPlaceholdersAreConsistentAcrossMessages(): void
    {
        $agent = $this->agent(static fn (): string => 'ok');

        $agent->call(new MessageBag(
            Message::forSystem('Konto der Behörde: IBAN DE89 3704 0044 0532 0130 00'),
            Message::ofAssistant('Ihr Konto AT61 1904 3002 3457 3201 ist hinterlegt.'),
            Message::ofUser('Überweise von AT61 1904 3002 3457 3201 auf DE89 3704 0044 0532 0130 00.'),
        ))->getResult();

        $messages = $this->sentMessages();
        self::assertSame('Konto der Behörde: IBAN ⟦IBAN_1⟧', $messages[0]['content']);
        self::assertSame('Ihr Konto ⟦IBAN_2⟧ ist hinterlegt.', $messages[1]['content']);
        self::assertSame('Überweise von ⟦IBAN_2⟧ auf ⟦IBAN_1⟧.', $messages[2]['content']);
    }

    public function testMultiPartUserMessagesAreRedactedPartByPart(): void
    {
        $agent = $this->agent(static fn (): string => 'ok');

        $agent->call(new MessageBag(Message::ofUser(new Text('SVNR 4568 150392'), new Text('nochmal: 4568 150392'))))->getResult();

        self::assertStringNotContainsString('4568 150392', $this->sentPayload());
        self::assertSame(2, substr_count($this->sentPayload(), '⟦SVNR_1⟧'));
    }

    public function testImagesAreRejectedBeforeAnythingIsSent(): void
    {
        $agent = $this->agent(static fn (): string => 'ok');

        try {
            $agent->call(new MessageBag(Message::ofUser('Siehe Foto', new Image('fake-binary', 'image/png'))))->getResult();
            self::fail('Expected UnsupportedContentException');
        } catch (UnsupportedContentException) {
        }

        self::assertSame([], $this->client->getCalls());
    }

    public function testStructuredOutputIsRestoredDeeply(): void
    {
        $due = new \DateTimeImmutable('2026-11-15');
        $agent = $this->agent(static fn (): ResultInterface => new ObjectResult(new Explanation(
            summary: 'Das Finanzamt schreibt ⟦PERSON_1⟧.',
            actions: ['Überweisen Sie auf ⟦IBAN_1⟧', 'Fragen? Ihre SVNR: ⟦SVNR_1⟧'],
            payment: new Payment('⟦IBAN_1⟧', 'Steuer 2026', $due),
            amountInCents: 124500,
        )));

        $result = $agent->call(new MessageBag(Message::ofUser(self::LETTER)))->getResult();

        $explanation = $result->getContent();
        self::assertInstanceOf(Explanation::class, $explanation);
        self::assertSame('Das Finanzamt schreibt Johann Beispielmann.', $explanation->summary);
        self::assertSame(['Überweisen Sie auf AT61 1904 3002 3457 3201', 'Fragen? Ihre SVNR: 4568 150392'], $explanation->actions);
        self::assertSame('AT61 1904 3002 3457 3201', $explanation->payment->iban);
        self::assertSame('Steuer 2026', $explanation->payment->reference);
        self::assertSame($due, $explanation->payment->dueDate);
        self::assertSame(124500, $explanation->amountInCents);
    }

    public function testInventedPlaceholdersAreReportedInMetadata(): void
    {
        $agent = $this->agent(static fn (): string => '⟦PERSON_1⟧ und ⟦PERSON_9⟧');

        $result = $agent->call(new MessageBag(Message::ofUser(self::LETTER)))->getResult();

        self::assertSame('Johann Beispielmann und ⟦PERSON_9⟧', $result->getContent());
        self::assertSame(['⟦PERSON_9⟧'], $result->getMetadata()->get(PiiShieldProcessor::METADATA_KEY)['unknown_placeholders'] ?? null);
    }

    public function testOutputOfAnUnprocessedInputIsLeftAlone(): void
    {
        $processor = new PiiShieldProcessor(PiiShield::withDefaultDetectors());
        $agent = $this->agent(static fn (): string => '⟦PERSON_1⟧', inputProcessors: [], outputProcessors: [$processor]);

        $result = $agent->call(new MessageBag(Message::ofUser('Hallo')))->getResult();

        self::assertSame('⟦PERSON_1⟧', $result->getContent());
    }

    /**
     * @param \Closure(): (string|ResultInterface) $respond
     * @param list<InputProcessorInterface>|null   $inputProcessors
     * @param list<OutputProcessorInterface>|null  $outputProcessors
     */
    private function agent(\Closure $respond, ?array $inputProcessors = null, ?array $outputProcessors = null): Agent
    {
        $this->client = new MockModelClient($respond);
        $platform = new Platform(
            [new Provider('mock', [$this->client], [new MockResultConverter()], new FallbackModelCatalog())],
            new CatalogBasedModelRouter(),
        );
        $processor = new PiiShieldProcessor(PiiShield::withDefaultDetectors());

        return new Agent($platform, 'test-model', $inputProcessors ?? [$processor], $outputProcessors ?? [$processor]);
    }

    private function sentPayload(): string
    {
        self::assertCount(1, $this->client->getCalls());

        return json_encode($this->client->getCalls()[0]['payload'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function sentMessages(): array
    {
        $payload = $this->client->getCalls()[0]['payload'];
        self::assertIsArray($payload);
        self::assertIsArray($payload['messages']);

        /** @var list<array{role: string, content: string}> $messages */
        $messages = $payload['messages'];

        return $messages;
    }
}
