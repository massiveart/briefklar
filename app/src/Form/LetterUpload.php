<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

final class LetterUpload
{
    public const array MIME_TYPES = ['application/pdf', 'image/png', 'image/jpeg', 'image/tiff'];

    /** 10 MB as Symfony counts "10M" (10^6 bytes); the dropzone checks the same limit before upload. */
    public const int MAX_BYTES = 10_000_000;

    #[Assert\NotNull(message: 'letter.upload.missing')]
    #[Assert\File(
        maxSize: self::MAX_BYTES,
        mimeTypes: self::MIME_TYPES,
        maxSizeMessage: 'letter.upload.too_large',
        mimeTypesMessage: 'letter.upload.unsupported_type',
    )]
    public ?UploadedFile $letter = null;

    public bool $easyLanguage = false;
}
