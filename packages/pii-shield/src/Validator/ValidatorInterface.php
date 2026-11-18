<?php

declare(strict_types=1);

namespace Clarifile\PiiShield\Validator;

interface ValidatorInterface
{
    public function isValid(string $candidate): bool;
}
