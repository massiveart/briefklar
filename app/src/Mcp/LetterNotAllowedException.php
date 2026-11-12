<?php

declare(strict_types=1);

namespace App\Mcp;

/**
 * A path the MCP client asked for is not a letter the server may read. The message goes back to the
 * client, so it names the rule, never a path outside the letter folder.
 */
final class LetterNotAllowedException extends \RuntimeException
{
}
