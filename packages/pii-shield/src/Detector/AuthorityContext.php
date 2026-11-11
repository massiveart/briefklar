<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Detector;

/**
 * Heuristic: is a contact detail (e-mail, phone) the sender authority's own contact?
 *
 * Authority contact data is needed for the action steps ("where do I send my objection?")
 * and is not the recipient's personal data, so it is kept by default. A line counts as
 * authority context if it speaks in first person plural ("erreichen Sie uns") or refers
 * to an authority or to filing something ("einzubringen").
 *
 * @internal
 */
final class AuthorityContext
{
    private const string KEYWORDS = '/\b(?:uns|wir|unser\w*|einzubringen|Einspruch|Beschwerde|Bezirkshauptmannschaft|Finanzamt\w*|Magistrat\w*|Behörde\w*|Gericht\w*|Amt|wenden\s+Sie\s+sich)\b/iu';

    /**
     * @param int $byteOffset byte offset of the value (RegexMatcher::matchAll() 'byteOffset')
     */
    public static function isAuthorityContact(string $text, int $byteOffset): bool
    {
        return 1 === preg_match(self::KEYWORDS, RegexMatcher::lineAround($text, $byteOffset));
    }

    /**
     * Whether a byte offset lies in the letterhead: the first block of the text (up to the first blank line or
     * the recipient's salutation) when it names an authority, e.g. "Magistrat der Stadt Musterhausen / Rathausplatz 2, 8999 Musterhausen".
     */
    public static function isInLetterhead(string $text, int $byteOffset): bool
    {
        // The letterhead ends at the first blank line, or earlier where the recipient block starts.
        $end = 1 === preg_match('/\n[ \t]*\n|^(?:Herrn?|Frau|An)\b/mu', $text, $boundary, \PREG_OFFSET_CAPTURE) ? $boundary[0][1] : \strlen($text);

        return $byteOffset < $end && 1 === preg_match(self::KEYWORDS, substr($text, 0, $end));
    }
}
