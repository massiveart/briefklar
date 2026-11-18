<?php

declare(strict_types=1);

namespace App\Sample;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The synthetic letters in /fixtures/letters, offered on the upload page for trying Clarifile
 * without a letter at hand. Letters that only model-based detection can redact ("detection": "model")
 * are offered only when it is switched on, so a sample never sends a name to the provider.
 */
final readonly class SampleLetters
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/../fixtures/letters')]
        private string $directory,
        #[Autowire('%env(bool:PII_MODEL_DETECTION)%')]
        private bool $modelDetection,
    ) {
    }

    /**
     * @return list<string> names like "01-strafverfuegung", sorted
     */
    public function names(): array
    {
        $files = glob($this->directory.'/*.txt');
        $names = [];
        foreach (false === $files ? [] : $files as $file) {
            $name = basename($file, '.txt');
            if ($this->modelDetection || !$this->needsModelDetection($name)) {
                $names[] = $name;
            }
        }
        sort($names);

        return $names;
    }

    /**
     * @throws \OutOfBoundsException if there is no such sample (only listed names, never a path)
     */
    public function text(string $name): string
    {
        if (!\in_array($name, $this->names(), true)) {
            throw new \OutOfBoundsException(\sprintf('No sample letter "%s".', $name));
        }

        $text = file_get_contents($this->directory.'/'.$name.'.txt');
        if (false === $text) {
            throw new \OutOfBoundsException(\sprintf('Sample letter "%s" is not readable.', $name));
        }

        return $text;
    }

    private function needsModelDetection(string $name): bool
    {
        $path = $this->directory.'/'.$name.'.expected.json';
        $json = is_file($path) ? file_get_contents($path) : false;
        if (false === $json) {
            return false;
        }
        $expected = json_decode($json, true);

        return \is_array($expected) && 'model' === ($expected['detection'] ?? null);
    }
}
