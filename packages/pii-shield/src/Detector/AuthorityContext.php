<?php

declare(strict_types=1);

namespace Clarifile\PiiShield\Detector;

/**
 * Heuristic: is a contact detail (e-mail, phone) the sender authority's own contact?
 *
 * Authority contact data is needed for the action steps ("where do I send my objection?")
 * and is not the recipient's personal data, so it is kept by default. A sentence counts as
 * authority context if it speaks in first person plural ("erreichen Sie uns") or refers
 * to an authority or to filing something ("einzubringen"). The sentence, not the whole line:
 * OCR and copied text put whole paragraphs on one line, and "Sie haben Einspruch erhoben.
 * Ihre Nummer ist ..." must not keep the recipient's number.
 *
 * Never authority context: a sentence about the recipient's contact ("Ihre Telefonnummer") and mobile
 * numbers (authorities publish landlines; see isMobileNumber()). First person singular is no signal:
 * case workers write "stehe ich Ihnen unter ... zur Verfügung".
 *
 * @internal
 */
final class AuthorityContext
{
    private const string KEYWORDS = '/\b(?:uns|wir|unser\w*|einzubringen|Einspruch|Beschwerde|Bezirkshauptmannschaft|Finanzamt\w*|Magistrat\w*|Behörde\w*|Gericht\w*|Amt)\b/iu';
    /** The recipient's own contact: "Ihre Telefonnummer", "Ihrer Rufnummer", "Ihre E-Mail-Adresse" (not "Ihrer E-Mail vom ..."). */
    private const string PERSONAL = '/\\bIhre[mnrs]?\\s+(?:(?:Telefon|Handy|Mobil|Ruf|Rückruf|Mobilfunk)\\w*|(?:E-Mail|Mail)-?Adresse\\w*|Kontaktdaten)\\b/u';
    /**
     * Mobile numbers. AT: the common mobile blocks only (0662, 065xx, 07xxx are landlines). DE and CH only with
     * the country code: national "015x" or "07xx" are Austrian landlines too. Numbers not listed fall back to
     * the authority-context rule, as before.
     */
    private const string MOBILE = '/^(?:(?:\\+|00)43|0)(?:650|660|664|676|677|678|680|681|688|699)|^(?:\\+|00)49(?:15|16|17)|^(?:\\+|00)41(?:7[5-9])/';
    /** Names of authorities: a letterhead needs one ("uns" or "wir" are not enough there). */
    private const string AUTHORITY_NAMES = '/\b(?:Bezirkshauptmannschaft|Finanzamt|Magistrat|Magistratsabteilung|Behörde|Gericht|Bezirksgericht|Landesgericht|Amtsgericht|Amt|Gemeindeamt|Gemeinde|Stadtgemeinde|Marktgemeinde|Landesregierung|Ministerium|Polizei|Polizeiinspektion|Landespolizeidirektion|Stadtverwaltung)\b/iu';
    /** Short forms authorities put before their name in a domain ("bh-musterstadt.gv.at"). */
    private const array SECOND_LEVEL_DOMAINS = ['gv', 'co', 'or', 'ac', 'gov'];
    private const array DOMAIN_PREFIXES = ['bh', 'fa', 'ma', 'mag', 'gem', 'stadt', 'markt', 'land', 'lpd', 'bg', 'lg'];
    /** The authority's name must be on one of the first lines of the letterhead. */
    private const int AUTHORITY_NAME_LINES = 2;
    private const int LETTERHEAD_LINES = 6;
    private const int LETTERHEAD_BYTES = 2_000;
    private const int SENTENCE_WINDOW = 400;
    /** Abbreviations whose dot does not end a sentence ("Abt. Verkehr, Tel. 05572 ..."). */
    private const array ABBREVIATIONS = ['Abs', 'Abt', 'Art', 'bzw', 'ca', 'Dr', 'DI', 'Fa', 'Fr', 'gem', 'GZ', 'Hr', 'inkl', 'Ing', 'lit', 'lt', 'Mag', 'Nr', 'Prof', 'Str', 'Tel', 'usw', 'vgl', 'z.B', 'Zl'];

    /**
     * @param int $byteOffset byte offset of the value (RegexMatcher::matchAll() 'byteOffset')
     */
    public static function isAuthorityContact(string $text, int $byteOffset): bool
    {
        $sentence = self::sentenceAround($text, $byteOffset);

        return 1 === preg_match(self::KEYWORDS, $sentence) && 1 !== preg_match(self::PERSONAL, $sentence);
    }

    /**
     * Whether a phone number is a mobile number (AT, DE, CH). Mobile numbers are treated as personal even
     * next to authority keywords: "Ihren Einspruch haben wir unter 0664 ... notiert".
     */
    public static function isMobileNumber(string $phone): bool
    {
        $digits = (string) preg_replace('/[^\d+]/', '', $phone);
        // "+43 0664 ..." / "+43 (0)664 ...": the trunk zero after the country code is not part of the number
        $digits = (string) preg_replace('/^((?:\+|00)\d{2})0/', '$1', $digits);

        return 1 === preg_match(self::MOBILE, $digits);
    }

    /**
     * The sentence around a byte offset, within its line. A sentence ends at ".", "!" or "?" followed by
     * whitespace and an upper-case letter, unless the dot ends a known abbreviation or an initial.
     *
     * Only SENTENCE_WINDOW bytes on each side are looked at: OCR text can be one long line with many
     * contacts, and scanning the whole line per contact would be quadratic.
     */
    private static function sentenceAround(string $text, int $byteOffset): string
    {
        $length = \strlen($text);
        $from = max(0, $byteOffset - self::SENTENCE_WINDOW);
        $to = min($length, $byteOffset + self::SENTENCE_WINDOW);
        while ($from > 0 && 0x80 === (\ord($text[$from]) & 0xC0)) { // start and end on whole UTF-8 characters
            --$from;
        }
        while ($to < $length && 0x80 === (\ord($text[$to]) & 0xC0)) {
            ++$to;
        }
        $window = substr($text, $from, $to - $from);
        $offset = $byteOffset - $from;

        $lineStart = strrpos(substr($window, 0, $offset), "\n");
        if (false !== $lineStart) {
            $window = substr($window, $lineStart + 1);
            $offset -= $lineStart + 1;
        }
        $lineEnd = strpos($window, "\n", $offset);
        if (false !== $lineEnd) {
            $window = substr($window, 0, $lineEnd);
        }

        $abbreviations = implode('|', array_map(static fn (string $a): string => '\\b'.preg_quote($a, '/'), self::ABBREVIATIONS));
        $sentences = preg_split('/(?<!'.$abbreviations.'|\\b\\p{Lu})[.!?]\\s+(?=\\p{Lu})/u', $window, flags: \PREG_SPLIT_OFFSET_CAPTURE);
        foreach (array_reverse(false === $sentences ? [] : $sentences) as [$sentence, $start]) {
            if ($start <= $offset) {
                return $sentence;
            }
        }

        return $window;
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
        // "bh-musterstadt.gv.at": the name is the label before a second-level domain like "gv" or "co"
        $nameIndex = \count($labels) - 2;
        if ($nameIndex > 0 && \in_array($labels[$nameIndex], self::SECOND_LEVEL_DOMAINS, true)) {
            --$nameIndex;
        }
        $parts = explode('-', $labels[$nameIndex]);
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
