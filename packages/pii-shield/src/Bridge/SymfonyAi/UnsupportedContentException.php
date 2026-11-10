<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Bridge\SymfonyAi;

/**
 * A message contains content that cannot be redacted (images, audio, documents, URLs).
 * Such content would reach the provider with personal data, so the call is stopped.
 * Extract text locally first (e.g. OCR) and send only text.
 */
final class UnsupportedContentException extends \InvalidArgumentException
{
}
