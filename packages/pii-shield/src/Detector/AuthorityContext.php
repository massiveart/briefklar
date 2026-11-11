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
    private const string KEYWORDS = '/\b(?:uns|wir|unser\w*|einzubringen|Einspruch|Beschwerde|Bezirkshauptmannschaft|Finanzamt\w*|Magistrat\w*|Behörde\w*|Gericht\w*|Amt)\b/iu';
    /** Names of authorities: a letterhead needs one ("uns" or "wir" are not enough there). */
    private const string AUTHORITY_NAMES = '/\b(?:Bezirkshauptmannschaft|Finanzamt\w*|Magistrat\w*|Behörde\w*|Gericht\w*|Amt|Amtsgericht|Gemeinde\w*|Stadtgemeinde|Marktgemeinde|Landesregierung|Ministerium|Polizei\w*|Landespolizeidirektion|Stadtverwaltung)\b/iu';
    private const int LETTERHEAD_LINES = 6;
    private const int LETTERHEAD_BYTES = 2_000;

    /**
     * @param int $byteOffset byte offset of the value (RegexMatcher::matchAll() 'byteOffset')
     */
    public static function isAuthorityContact(string $text, int $byteOffset): bool
    {
        return 1 === preg_match(self::KEYWORDS, RegexMatcher::lineAround($text, $byteOffset));
    }

    /**
     * Whether a byte offset lies in the letterhead of an authority (see letterhead()).
     */
    public static function isInLetterhead(string $text, int $byteOffset): bool
    {
        $letterhead = self::letterhead($text);

        return null !== $letterhead && $byteOffset < \strlen($letterhead);
    }

    /**
     * Whether an e-mail address belongs to the authority in the letterhead: a part of its domain matches a
     * word of the letterhead ("parken@musterhausen.example" for "Magistrat der Stadt Musterhausen").
     */
    public static function isAuthorityEmail(string $text, string $email): bool
    {
        $letterhead = self::letterhead($text);
        $at = strrpos($email, '@');
        if (null === $letterhead || false === $at) {
            return false;
        }
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($letterhead), flags: \PREG_SPLIT_NO_EMPTY);
        $labels = preg_split('/[.-]+/', strtolower(substr($email, $at + 1)), flags: \PREG_SPLIT_NO_EMPTY);
        foreach (false === $labels ? [] : $labels as $label) {
            foreach (false === $words ? [] : $words as $word) {
                if (mb_strlen($word) >= 5 && str_contains($label, $word)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The letterhead: the first block of the text, ending at a blank line or at the recipient's salutation
     * within the first LETTERHEAD_LINES lines, and only when it names an authority. Otherwise null: without
     * a clear letterhead everything is treated as personal data.
     */
    private static function letterhead(string $text): ?string
    {
        // Only the start of the text is searched: this runs for every e-mail found and must not scan the text.
        $head = substr($text, 0, self::LETTERHEAD_BYTES);
        $limit = 1 === preg_match('/\A(?:[^\r\n]*\R){'.self::LETTERHEAD_LINES.'}/', $head, $lines) ? \strlen($lines[0]) : \strlen($head);
        $head = substr($head, 0, $limit + 2); // a blank line right after the last allowed line still ends the block
        if (1 !== preg_match('/\R[ \t]*\R|^(?:Herrn?|Frau|An)\b/mu', $head, $boundary, \PREG_OFFSET_CAPTURE) || $boundary[0][1] > $limit) {
            return null;
        }
        $letterhead = substr($text, 0, $boundary[0][1]);

        return 1 === preg_match(self::AUTHORITY_NAMES, $letterhead) ? $letterhead : null;
    }
}
