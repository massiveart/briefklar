<?php

declare(strict_types=1);

namespace App\Extraction\Ocr;

use App\Extraction\ExtractionFailedException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;

/**
 * Local OCR via the Tesseract CLI. Images never leave the machine.
 */
final readonly class TesseractOcr
{
    public function __construct(
        private string $binary = 'tesseract',
        private string $language = 'deu',
        private float $timeoutSeconds = 60.0,
    ) {
    }

    public function recognize(string $imagePath): string
    {
        $process = new Process([$this->binary, $imagePath, 'stdout', '-l', $this->language], timeout: $this->timeoutSeconds);

        try {
            $process->mustRun();
        } catch (ProcessFailedException|ProcessTimedOutException|ProcessRuntimeException $e) {
            // Do not include process output: it may contain recognized personal data.
            throw new ExtractionFailedException(\sprintf('Tesseract failed (exit code %s).', $process->getExitCode() ?? 'n/a'), previous: $e);
        }

        return $process->getOutput();
    }
}
