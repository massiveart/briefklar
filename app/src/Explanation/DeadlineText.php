<?php

declare(strict_types=1);

namespace App\Explanation;

/**
 * The deadline field as the model writes it (LetterExplanation): the deadline itself, then " – " and
 * the sentence of the letter it came from. The deadline is what DeadlineCheck verifies and what the
 * result page shows prominently; the sentence is shown apart, as a quote to compare with the original.
 */
final readonly class DeadlineText
{
    private function __construct(
        public string $claim,
        public ?string $source,
    ) {
    }

    public static function parse(string $deadline): self
    {
        // A dash with spaces on both sides; a hyphenated word ("Jänner-Frist") is no separator.
        $parts = preg_split('/\s[–-]\s/u', trim($deadline), 2);
        if (false === $parts) {
            return new self(trim($deadline), null);
        }
        $source = trim($parts[1] ?? '');

        return new self(trim($parts[0]), '' === $source ? null : $source);
    }

    public function withoutSource(): self
    {
        return new self($this->claim, null);
    }

    /**
     * The deadline as a date if the model reported an ISO date that exists, null for a period.
     */
    public function date(): ?\DateTimeImmutable
    {
        if (1 !== preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $this->claim, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return new \DateTimeImmutable($this->claim);
    }
}
