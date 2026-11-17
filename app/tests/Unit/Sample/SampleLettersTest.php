<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sample;

use App\Sample\SampleLetters;
use PHPUnit\Framework\TestCase;

final class SampleLettersTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/briefklar_samples_'.bin2hex(random_bytes(6));
        mkdir($this->directory);
        file_put_contents($this->directory.'/02-regex.txt', 'Brief zwei');
        file_put_contents($this->directory.'/02-regex.expected.json', '{"pii": []}');
        file_put_contents($this->directory.'/01-plain.txt', 'Brief eins');
        file_put_contents($this->directory.'/03-model.txt', 'Brief drei');
        file_put_contents($this->directory.'/03-model.expected.json', '{"pii": [], "detection": "model"}');
        file_put_contents($this->directory.'/README.md', 'not a letter');
    }

    protected function tearDown(): void
    {
        $files = glob($this->directory.'/*');
        array_map('unlink', false === $files ? [] : $files);
        rmdir($this->directory);
    }

    public function testListsLettersSortedWithoutModelOnlyOnes(): void
    {
        self::assertSame(['01-plain', '02-regex'], (new SampleLetters($this->directory, false))->names());
    }

    public function testListsModelOnlyLettersWhenModelDetectionIsOn(): void
    {
        self::assertSame(['01-plain', '02-regex', '03-model'], (new SampleLetters($this->directory, true))->names());
    }

    public function testReturnsTheTextOfAListedLetter(): void
    {
        self::assertSame('Brief eins', (new SampleLetters($this->directory, false))->text('01-plain'));
    }

    public function testRefusesModelOnlyLettersWhenModelDetectionIsOff(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        (new SampleLetters($this->directory, false))->text('03-model');
    }

    public function testRefusesAnythingThatIsNotAListedName(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        (new SampleLetters($this->directory, true))->text('../01-plain');
    }
}
