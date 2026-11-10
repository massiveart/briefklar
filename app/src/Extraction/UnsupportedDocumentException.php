<?php

declare(strict_types=1);

namespace App\Extraction;

final class UnsupportedDocumentException extends \RuntimeException
{
    public static function forMimeType(string $mimeType): self
    {
        return new self(\sprintf('No text extractor supports "%s".', $mimeType));
    }
}
