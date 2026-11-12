<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp;

use App\Mcp\LetterDirectory;
use App\Mcp\LetterNotAllowedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LetterDirectoryTest extends TestCase
{
    private string $root;
    private string $letters;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/briefklar_mcp_'.bin2hex(random_bytes(6));
        $this->letters = $this->root.'/letters';
        mkdir($this->letters.'/2026', recursive: true);
        foreach (['brief.pdf', 'Foto.JPG', 'scan.tiff', '2026/nov.png', 'notes.txt'] as $file) {
            file_put_contents($this->letters.'/'.$file, 'x');
        }
        file_put_contents($this->root.'/secret.pdf', 'x');
        symlink($this->root.'/secret.pdf', $this->letters.'/link.pdf');
    }

    protected function tearDown(): void
    {
        foreach (['letters/link.pdf', 'letters/brief.pdf', 'letters/Foto.JPG', 'letters/scan.tiff', 'letters/2026/nov.png', 'letters/notes.txt', 'secret.pdf'] as $file) {
            @unlink($this->root.'/'.$file);
        }
        rmdir($this->letters.'/2026');
        rmdir($this->letters);
        rmdir($this->root);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function allowed(): iterable
    {
        yield 'pdf' => ['brief.pdf', 'brief.pdf'];
        yield 'upper case extension' => ['Foto.JPG', 'Foto.JPG'];
        yield 'tiff' => ['scan.tiff', 'scan.tiff'];
        yield 'subfolder' => ['2026/nov.png', '2026/nov.png'];
        yield 'dot segment that stays inside' => ['2026/../brief.pdf', 'brief.pdf'];
    }

    #[DataProvider('allowed')]
    public function testResolvesFilesInsideTheFolder(string $path, string $expected): void
    {
        self::assertSame(realpath($this->letters.'/'.$expected), (new LetterDirectory($this->letters))->resolve($path));
    }

    public function testAcceptsAnAbsolutePathInsideTheFolder(): void
    {
        $absolute = realpath($this->letters).'/brief.pdf';

        self::assertSame($absolute, (new LetterDirectory($this->letters))->resolve($absolute));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'parent folder' => ['../secret.pdf'];
        yield 'deep parent' => ['2026/../../secret.pdf'];
        yield 'absolute path outside' => ['/etc/passwd'];
        yield 'symlink to outside' => ['link.pdf'];
        yield 'wrong type' => ['notes.txt'];
        yield 'missing file' => ['nope.pdf'];
        yield 'folder' => ['2026'];
        yield 'empty' => [''];
    }

    #[DataProvider('refused')]
    public function testRefusesEverythingElse(string $path): void
    {
        $this->expectException(LetterNotAllowedException::class);
        (new LetterDirectory($this->letters))->resolve($path);
    }

    public function testRefusesWhenNoFolderIsConfigured(): void
    {
        $this->expectException(LetterNotAllowedException::class);
        $this->expectExceptionMessage('MCP_LETTER_DIR');
        (new LetterDirectory(''))->resolve('brief.pdf');
    }

    public function testMessagesDoNotRevealPathsOutsideTheFolder(): void
    {
        try {
            (new LetterDirectory($this->letters))->resolve('../secret.pdf');
            self::fail('Expected LetterNotAllowedException.');
        } catch (LetterNotAllowedException $e) {
            self::assertStringNotContainsString($this->root, $e->getMessage());
        }
    }
}
