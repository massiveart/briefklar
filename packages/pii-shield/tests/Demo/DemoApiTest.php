<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Tests\Demo;

use Briefklar\PiiShield\Demo\DemoApi;
use Briefklar\PiiShield\Vault\EncryptedVault;
use Briefklar\PiiShield\Vault\Storage\Psr6CacheStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class DemoApiTest extends TestCase
{
    private const string LETTER = "Herrn\nJohann Beispielmann\n\nSVNR 4568 150392\nIBAN AT61 1904 3002 3457 3201\nSehr geehrter Herr Beispielmann,\nE-Mail an post@finanzamt.example einzubringen.";

    private DemoApi $api;

    protected function setUp(): void
    {
        $storage = new Psr6CacheStorage(new ArrayAdapter());
        $this->api = new DemoApi(new EncryptedVault($storage, EncryptedVault::generateKey()), $storage);
    }

    public function testReversibleAnalysisReturnsPlaceholdersPerFinding(): void
    {
        $response = $this->api->analyze(['text' => self::LETTER, 'mode' => 'reversible']);

        self::assertSame(
            "Herrn\n⟦PERSON_1⟧\n\nSVNR ⟦SVNR_1⟧\nIBAN ⟦IBAN_1⟧\nSehr geehrter Herr ⟦PERSON_1⟧,\nE-Mail an post@finanzamt.example einzubringen.",
            $response['output'],
        );
        self::assertSame(
            [['PERSON', 'Johann Beispielmann', '⟦PERSON_1⟧'], ['SVNR', '4568 150392', '⟦SVNR_1⟧'], ['IBAN', 'AT61 1904 3002 3457 3201', '⟦IBAN_1⟧'], ['PERSON', 'Beispielmann', '⟦PERSON_1⟧']],
            array_map(static fn (array $f): array => [$f['type'], $f['value'], $f['replacement']], $response['findings']),
        );
        self::assertSame($response['output'], implode('', array_column($response['segments'], 'text')));
    }

    public function testReversibleAnalysisStoresOnlyCiphertext(): void
    {
        $response = $this->api->analyze(['text' => self::LETTER, 'mode' => 'reversible']);

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $response['vaultId']);
        self::assertSame('Johann Beispielmann', $response['mapping']['⟦PERSON_1⟧'] ?? null);
        $stored = (string) base64_decode((string) $response['storedPayload'], true);
        self::assertNotSame('', $stored);
        self::assertStringNotContainsString('Johann', $stored);
    }

    public function testIrreversibleAnalysisUsesConfiguredStrategies(): void
    {
        $response = $this->api->analyze([
            'text' => self::LETTER,
            'mode' => 'irreversible',
            'strategies' => ['PERSON' => ['kind' => 'full'], 'SVNR' => ['kind' => 'partial', 'keepStart' => 0, 'keepEnd' => 3]],
        ]);

        self::assertSame(
            "Herrn\n****** ************\n\nSVNR **** ***392\nIBAN AT61 **** **** **** 3201\nSehr geehrter Herr ************,\nE-Mail an post@finanzamt.example einzubringen.",
            $response['output'],
        );
        self::assertNull($response['vaultId']);
        self::assertSame($response['output'], implode('', array_column($response['segments'], 'text')));
    }

    public function testOnlySelectedTypesAreDetected(): void
    {
        $response = $this->api->analyze(['text' => self::LETTER, 'mode' => 'reversible', 'types' => ['IBAN']]);

        self::assertSame(['IBAN'], array_column($response['findings'], 'type'));
    }

    public function testAllowlistedValuesAreKept(): void
    {
        $response = $this->api->analyze(['text' => self::LETTER, 'mode' => 'reversible', 'allowlist' => ['4568 150392']]);

        self::assertStringContainsString('SVNR 4568 150392', $response['output']);
    }

    public function testAuthorityContactsCanBeRedactedToo(): void
    {
        $response = $this->api->analyze(['text' => self::LETTER, 'mode' => 'reversible', 'keepAuthorityContacts' => false]);

        self::assertContains('EMAIL', array_column($response['findings'], 'type'));
    }

    public function testRestoreMarksRestoredAndUnknownPlaceholders(): void
    {
        $vaultId = (string) $this->api->analyze(['text' => self::LETTER, 'mode' => 'reversible'])['vaultId'];

        $response = $this->api->restore(['text' => 'Hallo ⟦person_1⟧, wer ist ⟦PERSON_7⟧?', 'vaultId' => $vaultId]);

        self::assertSame('Hallo Johann Beispielmann, wer ist ⟦PERSON_7⟧?', $response['text']);
        self::assertSame(['⟦PERSON_7⟧'], $response['unknown']);
        self::assertSame($response['text'], implode('', array_column($response['segments'], 'text')));
        self::assertSame(
            [['Johann Beispielmann', 'restored'], ['⟦PERSON_7⟧', 'unknown']],
            array_values(array_map(
                static fn (array $s): array => [$s['text'], $s['status']],
                array_filter($response['segments'], static fn (array $s): bool => 'plain' !== $s['status']),
            )),
        );
    }

    public function testRestoreWithUnknownVaultIdFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->api->restore(['text' => '⟦PERSON_1⟧', 'vaultId' => 'nope']);
    }

    public function testInvalidModeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->api->analyze(['text' => 'x', 'mode' => 'magic']);
    }
}
