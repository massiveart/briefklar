<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Exception;

/**
 * The detection model did not return a usable answer. Detection fails closed: continuing without
 * the model's findings could send personal data on unredacted. The message never contains the text.
 */
final class DetectionFailedException extends \RuntimeException implements ExceptionInterface
{
}
