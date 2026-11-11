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
    private const string AUTHORITY_NAMES = '/\b(?:Bezirkshauptmannschaft|Finanzamt|Magistrat|Magistratsabteilung|Behörde|Gericht|Bezirksgericht|Landesgericht|Amtsgericht|Amt|Gemeindeamt|Gemeinde|Stadtgemeinde|Marktgemeinde|Landesregierung|Ministerium|Polizei|Polizeiinspektion|Landespolizeidirektion|Stadtverwaltung)\b/iu';
    /** Short forms authorities put before their name in a domain ("bh-musterstadt.gv.at"). */
    private const array DOMAIN_PREFIXES = ['bh', 'fa', 'ma', 'mag', 'gem', 'stadt', 'markt', 'land', 'lpd', 'bg', 'lg'];
    /** The authority's name must be on one of the first lines of the letterhead. */
    private const int AUTHORITY_NAME_LINES = 2;
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
     * Whether an e-mail address belongs to the authority in the letterhead: the domain's name (the label
     * before the top-level domain, e.g. "musterhausen" in "parken@musterhausen.example") equals a word of the
     * line that names the authority, optionally after a known short form ("bh-musterstadt"). A similar
     * domain ("musterhausen-fitness") does not count: when in doubt, the address is redacted.
     */
    public static function isAuthorityEmail(string $text, string $email): bool
    {
        $authority = self::authorityLine($text);
        $at = strrpos($email, '@');
        if (null === $authority || false === $at) {
            return false;
        }
        $labels = explode('.', strtolower(substr($email, $at + 1)));
        if (\count($labels) < 2) {
            return false;
        }
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($authority), flags: \PREG_SPLIT_NO_EMPTY);
        $words = array_filter(false === $words ? [] : $words, static fn (string $w): bool => mb_strlen($w) >= 5);
        $parts = explode('-', $labels[\count($labels) - 2]);
        $name = array_pop($parts);

        return \in_array($name, $words, true) && [] === array_diff($parts, self::DOMAIN_PREFIXES);
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

        return null !== self::authorityLineOf($letterhead) ? $letterhead : null;
    }

    /** The line of the letterhead that names the authority, or null. */
    private static function authorityLine(string $text): ?string
    {
        $letterhead = self::letterhead($text);

        return null === $letterhead ? null : self::authorityLineOf($letterhead);
    }

    private static function authorityLineOf(string $letterhead): ?string
    {
        $lines = preg_split('/\R/', $letterhead);
        foreach (\array_slice(false === $lines ? [] : $lines, 0, self::AUTHORITY_NAME_LINES) as $line) {
            if (1 === preg_match(self::AUTHORITY_NAMES, $line)) {
                return $line;
            }
        }

        return null;
    }
}
