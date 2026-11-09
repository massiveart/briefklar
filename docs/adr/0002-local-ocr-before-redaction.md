# ADR 0002: Local OCR before redaction, no vision models

## Status
Accepted (09.11.2026)

## Context
Letters arrive as photos or PDFs. Sending images to a multimodal model would be the easiest way to read them, but the image itself contains all personal data. Redaction can only happen on text.

## Decision
Text extraction happens locally: PDF text layer if present, otherwise Tesseract with the German language pack inside the app container. Only redacted text is sent to any model.

## Consequences
OCR quality is lower than with vision models on bad photos. Acceptable for the demo (scanned PDFs). This is the core privacy promise of the project and the main thing to show in the video.
