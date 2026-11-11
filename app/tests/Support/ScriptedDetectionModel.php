<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\AI\Platform\Exception\RuntimeException as PlatformException;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Test replacement for the local Ollama that pii-shield's ModelDetector asks (config/services_test.yaml).
 * Answers like a detection model: every known value that occurs in the text, as JSON.
 * Records every request, so tests can check whether the model was asked at all.
 */
final class ScriptedDetectionModel
{
    /** A text containing this marker behaves like an unreachable detection model. */
    public const string UNREACHABLE_MARKER = 'ERKENNUNGSMODELL-NICHT-ERREICHBAR';

    /** Values a real model finds and the regex detectors miss (app fixture 03). */
    private const array KNOWN = [
        'Lukas Testmann' => 'PERSON',
        'Gartengasse 5, 8990 Beispieldorf' => 'ADDRESS',
    ];

    /** @var list<string> */
    private array $requests = [];

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
        $request = json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        $this->requests[] = $request;

        if (str_contains($request, self::UNREACHABLE_MARKER)) {
            throw new PlatformException('Scripted detection model is not reachable.');
        }

        $entities = [];
        foreach (self::KNOWN as $value => $type) {
            if (str_contains($request, $value)) {
                $entities[] = ['type' => $type, 'value' => $value];
            }
        }

        return json_encode(['entities' => $entities], \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }
}
