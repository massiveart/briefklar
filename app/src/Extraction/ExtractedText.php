<?php

declare(strict_types=1);

namespace App\Extraction;

/**
 * Plain text of an uploaded letter. Contains personal data: never log or persist it.
 */
final readonly class ExtractedText
{
    public function __construct(
        public string $text,
        public ExtractionMethod $method,
    ) {
    }

    public function __debugInfo(): array
    {
        return ['method' => $this->method, 'length' => mb_strlen($this->text)];
    }
}
