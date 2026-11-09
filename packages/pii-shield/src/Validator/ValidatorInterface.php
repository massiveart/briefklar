<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Validator;

interface ValidatorInterface
{
    public function isValid(string $candidate): bool;
}
