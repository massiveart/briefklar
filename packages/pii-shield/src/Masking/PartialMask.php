<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Masking;

use Briefklar\PiiShield\Entity;

/**
 * Keeps the first and last n characters, masks everything else except whitespace:
 * "AT61 1904 3002 3457 3201" -> "AT61 **** **** **** 3201".
 *
 * Values not longer than keepStart + keepEnd are masked completely, otherwise nothing would be hidden.
 * Leaks length and format by design; meant for logs and previews, not for model providers.
 */
final class PartialMask implements MaskingStrategyInterface
{
    public function __construct(
        private readonly int $keepStart,
        private readonly int $keepEnd,
        private readonly string $maskChar = '*',
    ) {
        if ($keepStart < 0 || $keepEnd < 0) {
            throw new \InvalidArgumentException('Characters to keep must not be negative.');
        }
    }

    public function mask(Entity $entity): string
    {
        $chars = mb_str_split($entity->value);
        $visible = array_values(array_filter($chars, static fn (string $c): bool => !ctype_space($c)));
        $fullyMasked = \count($visible) <= $this->keepStart + $this->keepEnd;

        $result = '';
        $position = 0;
        foreach ($chars as $char) {
            if (ctype_space($char)) {
                $result .= $char;
                continue;
            }
            $keep = !$fullyMasked && ($position < $this->keepStart || $position >= \count($visible) - $this->keepEnd);
            $result .= $keep ? $char : $this->maskChar;
            ++$position;
        }

        return $result;
    }
}
