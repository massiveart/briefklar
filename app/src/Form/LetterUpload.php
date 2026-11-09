<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

final class LetterUpload
{
    public const array MIME_TYPES = ['application/pdf', 'image/png', 'image/jpeg', 'image/tiff'];

    #[Assert\NotNull(message: 'letter.upload.missing')]
    #[Assert\File(
        maxSize: '10M',
        mimeTypes: self::MIME_TYPES,
        maxSizeMessage: 'letter.upload.too_large',
        mimeTypesMessage: 'letter.upload.unsupported_type',
    )]
    public ?UploadedFile $letter = null;
}
