<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Detector;

use Briefklar\PiiShield\Entity;
use Briefklar\PiiShield\EntityType;

/**
 * Offline fallback for person names: names following "Herr", "Herrn" or "Frau".
 *
 * - Address block: salutation alone on a line, name on the next line ("Frau\nMaria Musterfrau").
 * - Inline: "Sehr geehrter Herr Beispielmann", up to two capitalised words. A following German
 *   noun ("Herr Muster Bescheid") is indistinguishable from a surname without NER; over-redaction
 *   is preferred to leaking half a name.
 *
 * Academic titles are skipped and not part of the value. Names without salutation
 * (e.g. the signing official) are left to the NER detector.
 */
final class SalutationPersonDetector implements DetectorInterface
{
    private const string TITLES = '(?:(?:Dr|Mag|DI|Dipl\.-Ing|Ing|Prof|MMag|Mag\.\(FH\))\.?[ \t]+)*';
    private const string WORD = "[A-ZÄÖÜ][\\p{L}'-]+";

    public function detect(string $text): array
    {
        $word = self::WORD;
        $titles = self::TITLES;

        $block = "/^(?:Herrn?|Frau)[ \\t]*\\n{$titles}(?<name>{$word}(?:[ \\t]+{$word})+)[ \\t]*$/mu";
        $inline = "/\\b(?:Herrn?|Frau)[ \\t]+{$titles}(?<name>{$word}(?:[ \\t]+{$word})?)/mu";

        // The address block is a strong signal; inline matches may include a following noun.
        $entities = [];
        foreach ([[$block, 0.8, 'salutation_block'], [$inline, 0.6, 'salutation_inline']] as [$pattern, $confidence, $name]) {
            foreach (RegexMatcher::matchAll($pattern, $text, 'name') as $match) {
                $entities[] = new Entity(EntityType::Person, $match['value'], $match['offset'], $confidence, $name);
            }
        }

        usort($entities, static fn (Entity $a, Entity $b): int => $a->offset <=> $b->offset);

        return $entities;
    }
}
