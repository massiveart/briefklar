<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Exception;

/**
 * The detection model did not return a usable answer. Detection fails closed: continuing without
 * the model's findings could send personal data on unredacted. The message never contains the text.
 */
final class DetectionFailedException extends \RuntimeException implements ExceptionInterface
{
    /**
     * @param string|null $modelAnswer the model's raw answer, for debugging only: it may contain personal data
     */
    public function __construct(
        string $message,
        ?\Throwable $previous = null,
        public readonly ?string $modelAnswer = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
