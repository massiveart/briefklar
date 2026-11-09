<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\AI\Platform\Exception\RuntimeException as PlatformException;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Test replacement for the provider (see config/services_test.yaml): answers with canned
 * explanations from tests/Fixtures/explanations and records every request it receives.
 *
 * Mocking the platform instead of the agent keeps the real agent pipeline under test:
 * system prompt, input/output processors (pii-shield) and structured output mapping.
 */
final class ScriptedPlatform
{
    /**
     * @var list<string> JSON-encoded payloads as they would be sent to the provider
     */
    private array $requests = [];

    /**
     * @var array<string, string> marker in the letter text => fixture name
     */
    private array $answers = [
        'STRAFVERFÜGUNG' => '01-strafverfuegung',
        'Buchungsmitteilung' => '02-finanzamt-vorschreibung', // deliberately wrong deadline
    ];

    public static function create(EventDispatcherInterface $dispatcher, self $recorder): Platform
    {
        return MockPlatformFactory::createPlatform(
            static fn (mixed $model, array|string|object $payload): string => $recorder->answer($payload),
            eventDispatcher: $dispatcher,
        );
    }

    /**
     * @return list<string>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * @param array<mixed>|string|object $payload
     */
    private function answer(array|string|object $payload): string
    {
        $request = json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        $this->requests[] = $request;

        foreach ($this->answers as $marker => $fixture) {
            if (str_contains($request, $marker)) {
                $answer = file_get_contents(\dirname(__DIR__).'/Fixtures/explanations/'.$fixture.'.json');
                if (false === $answer) {
                    throw new \RuntimeException(\sprintf('Missing canned explanation "%s".', $fixture));
                }

                return $answer;
            }
        }

        // Behaves like a provider outage for any other letter.
        throw new PlatformException('Scripted provider has no answer for this letter.');
    }
}
