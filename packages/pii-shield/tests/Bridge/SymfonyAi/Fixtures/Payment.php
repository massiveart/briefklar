<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Tests\Bridge\SymfonyAi\Fixtures;

final readonly class Payment
{
    public function __construct(
        public string $iban,
        public string $reference,
        public \DateTimeImmutable $dueDate,
    ) {
    }
}
