<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Exception;

/**
 * Stored vault data could not be read. The message never contains vault contents.
 */
final class VaultException extends \RuntimeException implements ExceptionInterface
{
}
