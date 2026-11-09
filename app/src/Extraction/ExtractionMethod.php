<?php

declare(strict_types=1);

namespace App\Extraction;

enum ExtractionMethod: string
{
    case PdfTextLayer = 'pdf_text_layer';
    case Ocr = 'ocr';
}
