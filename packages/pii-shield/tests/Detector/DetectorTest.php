<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Tests\Detector;

use Briefklar\PiiShield\Detector\AtSvnrDetector;
use Briefklar\PiiShield\Detector\BirthDateDetector;
use Briefklar\PiiShield\Detector\DetectorInterface;
use Briefklar\PiiShield\Detector\EmailDetector;
use Briefklar\PiiShield\Detector\FileNumberDetector;
use Briefklar\PiiShield\Detector\IbanDetector;
use Briefklar\PiiShield\Detector\LicensePlateDetector;
use Briefklar\PiiShield\Detector\PhoneDetector;
use Briefklar\PiiShield\Detector\PostalAddressDetector;
use Briefklar\PiiShield\Detector\SalutationPersonDetector;
use Briefklar\PiiShield\Detector\TaxNumberDetector;
use Briefklar\PiiShield\Entity;
use Briefklar\PiiShield\EntityType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DetectorTest extends TestCase
{
    /**
     * @return iterable<string, array{DetectorInterface, string, list<string>}>
     */
    public static function detectionProvider(): iterable
    {
        // SVNR
        yield 'svnr: valid with space' => [new AtSvnrDetector(), 'SVNR: 4568 150392.', ['4568 150392']];
        yield 'svnr: valid compact' => [new AtSvnrDetector(), 'Nr. 1237010180', ['1237010180']];
        yield 'svnr: wrong check digit' => [new AtSvnrDetector(), 'SVNR 1234 010180', []];
        yield 'svnr: part of longer number' => [new AtSvnrDetector(), 'Konto 912370101801', []];

        // IBAN
        yield 'iban: grouped' => [new IbanDetector(), 'IBAN AT61 1904 3002 3457 3201, danke', ['AT61 1904 3002 3457 3201']];
        yield 'iban: compact DE' => [new IbanDetector(), 'auf DE89370400440532013000 überweisen', ['DE89370400440532013000']];
        yield 'iban: followed by uppercase word' => [new IbanDetector(), 'AT61 1904 3002 3457 3201 BIC', ['AT61 1904 3002 3457 3201']];
        yield 'iban: invalid checksum' => [new IbanDetector(), 'AT62 1904 3002 3457 3201', []];

        // Tax numbers
        yield 'tax: AT Steuernummer' => [new TaxNumberDetector(), 'Steuernummer: 46 123/4567', ['46 123/4567']];
        yield 'tax: DE Steuernummer' => [new TaxNumberDetector(), 'StNr. 181/815/08155', ['181/815/08155']];
        yield 'tax: DE Steuer-ID valid' => [new TaxNumberDetector(), 'Steuer-ID 86095742719', ['86095742719']];
        yield 'tax: DE Steuer-ID invalid' => [new TaxNumberDetector(), 'Steuer-ID 86095742718', []];
        yield 'tax: amount is no tax number' => [new TaxNumberDetector(), 'Betrag 1.245,00 Euro', []];
        yield 'tax: several, in text order' => [new TaxNumberDetector(), "Steuer-ID 86095742719\nStNr. 181/815/08155\nSteuer-ID 24051867317", ['86095742719', '181/815/08155', '24051867317']];

        // Email
        yield 'email: private' => [new EmailDetector(), 'Kontakt: maria.musterfrau@example.com', ['maria.musterfrau@example.com']];
        yield 'email: authority contact kept' => [new EmailDetector(), 'Der Einspruch ist per E-Mail an post@bh.example einzubringen.', []];
        yield 'email: several private' => [new EmailDetector(), 'maria@example.org und johann@example.org', ['maria@example.org', 'johann@example.org']];
        yield 'email: private after an authority contact' => [new EmailDetector(), "Einspruch an post@bh.example einzubringen.\nPrivat: maria@example.org", ['maria@example.org']];
        yield 'email: authority keyword on another line' => [new EmailDetector(), "Finanzamt Linz\nKontakt: maria@example.org\nBescheid vom Finanzamt", ['maria@example.org']];
        yield 'email: authority contact redacted when disabled' => [new EmailDetector(keepAuthorityContacts: false), 'Bitte kontaktieren Sie uns unter post@bh.example.', ['post@bh.example']];

        // Phone
        yield 'phone: international' => [new PhoneDetector(), 'Mobil: +43 664 1234567', ['+43 664 1234567']];
        yield 'phone: national' => [new PhoneDetector(), 'Tel. 0664/1234567', ['0664/1234567']];
        yield 'phone: authority hotline kept' => [new PhoneDetector(), 'Bei Fragen erreichen Sie uns unter +43 50 233 233.', []];
        yield 'phone: several private' => [new PhoneDetector(), 'Mobil +43 664 1234567, Festnetz 05572 123456', ['+43 664 1234567', '05572 123456']];
        yield 'phone: private after an authority hotline' => [new PhoneDetector(), "Erreichen Sie uns unter +43 50 233 233.\nPrivat: 0664/1234567", ['0664/1234567']];
        yield 'phone: seven digits are enough' => [new PhoneDetector(), 'Tel. 0664 123', ['0664 123']];
        yield 'phone: six digits are not' => [new PhoneDetector(), 'Tel. 0664 12', []];
        yield 'phone: time is no phone' => [new PhoneDetector(), 'um 07:42 Uhr', []];
        yield 'phone: date is no phone' => [new PhoneDetector(), 'am 02.11.2026', []];

        // Birth date
        yield 'birth date: geb.' => [new BirthDateDetector(), 'geb. 15.03.1992', ['15.03.1992']];
        yield 'birth date: geboren am' => [new BirthDateDetector(), 'geboren am 1.3.1980 in Linz', ['1.3.1980']];
        yield 'birth date: Geburtsdatum' => [new BirthDateDetector(), 'Geburtsdatum: 01.01.1970', ['01.01.1970']];
        yield 'birth date: deadline is kept' => [new BirthDateDetector(), 'fällig am 15.11.2026', []];
        yield 'birth date: impossible date' => [new BirthDateDetector(), 'geb. 31.02.1992', []];

        // File numbers
        yield 'file number: Zahl' => [new FileNumberDetector(), "Zahl: BHMS-VK-2026/48213\n", ['BHMS-VK-2026/48213']];
        yield 'file number: Verwendungszweck' => [new FileNumberDetector(), 'Verwendungszweck: BHMS-VK-2026/48213.', ['BHMS-VK-2026/48213']];
        yield 'file number: Kundennummer' => [new FileNumberDetector(), 'Kundennummer 4711-0815', ['4711-0815']];
        yield 'file number: without digits' => [new FileNumberDetector(), 'Verwendungszweck: Ihre Steuernummer', []];
        yield 'file number: no keyword' => [new FileNumberDetector(), 'Buchungsmitteilung Nr. 3/2026', []];

        // License plates
        yield 'plate: AT' => [new LicensePlateDetector(), 'mit dem Kennzeichen B-123AB die', ['B-123AB']];
        yield 'plate: DE' => [new LicensePlateDetector(), 'Kennzeichen: M-AB 1234.', ['M-AB 1234']];
        yield 'plate: no keyword' => [new LicensePlateDetector(), 'Straße L 200 bei Strkm 4,2', []];

        // Persons (salutation heuristic)
        yield 'person: address block' => [new SalutationPersonDetector(), "Frau\nMaria Musterfrau\nLindenweg 12", ['Maria Musterfrau']];
        yield 'person: address block with title' => [new SalutationPersonDetector(), "Herrn\nDr. Johann Beispielmann\n", ['Johann Beispielmann']];
        yield 'person: inline salutation' => [new SalutationPersonDetector(), 'Sehr geehrter Herr Beispielmann,', ['Beispielmann']];
        yield 'person: inline first and last name' => [new SalutationPersonDetector(), 'wie mit Frau Maria Musterfrau besprochen', ['Maria Musterfrau']];
        // Known trade-off: a following noun is indistinguishable from a surname without NER.
        // Over-redaction is preferred to leaking half a name.
        yield 'person: inline over-redacts following noun' => [new SalutationPersonDetector(), 'Herr Beispielmann Bescheid gegeben', ['Beispielmann Bescheid']];
        yield 'person: inline takes at most two words' => [new SalutationPersonDetector(), 'Frau Maria Anna Musterfrau', ['Maria Anna']];
        yield 'person: no salutation' => [new SalutationPersonDetector(), 'Mag. Thomas Beispiel', []];
        // OCR output (Tesseract): blank lines in the address block, split words
        yield 'person: OCR blank line after salutation' => [new SalutationPersonDetector(), "Frau\n\nMaria Musterfrau\nLindenweg 12", ['Maria Musterfrau']];
        yield 'person: OCR split first name' => [new SalutationPersonDetector(), "Herrn\n\nJ ohann Beispielmann\n\nBahnhofstraße 7/3", ['J ohann Beispielmann']];
        yield 'person: OCR blank lines, at most two' => [new SalutationPersonDetector(), "Frau\n\n\n\nMaria Musterfrau", []];
        yield 'person: salutation ending a line in running text' => [new SalutationPersonDetector(), "Das haben wir mit unserem Herrn\n\nBitte zahlen Sie bis Ende", []];
        yield 'person: lone salutation before lower-case text' => [new SalutationPersonDetector(), "Frau\n\nbitte beachten Sie", []];

        // Postal addresses
        yield 'address: street and city' => [new PostalAddressDetector(), "Lindenweg 12\n6900 Bregenz\n", ["Lindenweg 12\n6900 Bregenz"]];
        yield 'address: door number' => [new PostalAddressDetector(), "Bahnhofstraße 7/3\n4020 Linz", ["Bahnhofstraße 7/3\n4020 Linz"]];
        yield 'address: DE postcode' => [new PostalAddressDetector(), "Musterallee 5a\n10115 Berlin", ["Musterallee 5a\n10115 Berlin"]];
        yield 'address: single line letterhead kept' => [new PostalAddressDetector(), 'Hauptplatz 1, 6999 Musterstadt', []];
        yield 'address: OCR blank line before city' => [new PostalAddressDetector(), "Bahnhofstraße 7/3\n\n4020 Linz\n", ["Bahnhofstraße 7/3\n\n4020 Linz"]];
        yield 'address: OCR two blank lines before city' => [new PostalAddressDetector(), "Lindenweg 12\n\n\n6900 Bregenz", ["Lindenweg 12\n\n\n6900 Bregenz"]];
        yield 'address: separate paragraphs are no address' => [new PostalAddressDetector(), "Rechtsmittelfrist 14\n\n\n\n6900 Bregenz", []];
    }

    /**
     * @param list<string> $expectedValues
     */
    #[DataProvider('detectionProvider')]
    public function testDetectsExpectedValues(DetectorInterface $detector, string $text, array $expectedValues): void
    {
        $entities = $detector->detect($text);

        self::assertSame($expectedValues, array_map(static fn (Entity $e): string => $e->value, $entities));
    }

    public function testOffsetsAreCharacterOffsetsAndMatchTheValue(): void
    {
        $text = "Grüße aus Österreich\nSehr geehrter Herr Beispielmann, Ihre SVNR lautet 4568 150392.";
        $detectors = [new SalutationPersonDetector(), new AtSvnrDetector()];

        foreach ($detectors as $detector) {
            foreach ($detector->detect($text) as $entity) {
                self::assertSame($entity->value, mb_substr($text, $entity->offset, $entity->length()));
            }
        }
    }

    public function testEntitiesCarryTypeAndDetectorName(): void
    {
        $entities = (new AtSvnrDetector())->detect('4568 150392');

        self::assertCount(1, $entities);
        self::assertSame(EntityType::AtSvnr, $entities[0]->type);
        self::assertSame('at_svnr', $entities[0]->detector);
    }
}
