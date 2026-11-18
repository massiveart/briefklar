<?php

declare(strict_types=1);

namespace Clarifile\PiiShield\Exception;

/**
 * A custom entity type could not be learned; the message says why and how to fix it.
 */
final class LearningFailedException extends \InvalidArgumentException implements ExceptionInterface
{
}
