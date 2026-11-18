<?php

declare(strict_types=1);

namespace App\Controller;

use App\Sample\LetterPdf;
use App\Sample\SampleLetters;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Synthetic sample letters to download and upload again, as text PDF or as scan (image only, read by OCR).
 */
final class SampleLetterController extends AbstractController
{
    public function __construct(
        private readonly SampleLetters $samples,
        private readonly LetterPdf $pdf,
    ) {
    }

    #[Route('/samples/{kind}/{name}.pdf', name: 'sample_letter', requirements: ['kind' => 'text|scan', 'name' => '[a-z0-9-]+'], methods: ['GET'])]
    public function download(string $kind, string $name): Response
    {
        try {
            $text = $this->samples->text($name);
        } catch (\OutOfBoundsException) {
            throw $this->createNotFoundException();
        }

        $pdf = 'scan' === $kind ? $this->pdf->scan($text) : $this->pdf->text($text);

        return new Response($pdf, headers: [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                \sprintf('clarifile-sample-%s%s.pdf', $name, 'scan' === $kind ? '-scan' : ''),
            ),
        ]);
    }
}
