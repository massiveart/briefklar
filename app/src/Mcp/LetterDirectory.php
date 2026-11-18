<?php

declare(strict_types=1);

namespace App\Mcp;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The one folder explain_letter_file may read from (MCP_LETTER_DIR).
 *
 * Without this check an MCP client could make the server read any file and send it to a model.
 * The path is resolved with realpath(), so "../" and symlinks that lead outside are refused,
 * and only document types the app can read are accepted.
 */
final readonly class LetterDirectory
{
    private const array EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'tif', 'tiff'];

    public function __construct(
        #[Autowire('%env(MCP_LETTER_DIR)%')]
        private string $directory,
    ) {
    }

    /**
     * @return string absolute real path of a readable letter inside the folder
     *
     * @throws LetterNotAllowedException
     */
    public function resolve(string $path): string
    {
        if ('' === trim($this->directory)) {
            throw new LetterNotAllowedException('No letter folder is configured on the server (MCP_LETTER_DIR).');
        }
        $root = realpath($this->directory);
        if (false === $root || !is_dir($root)) {
            throw new LetterNotAllowedException('The letter folder configured on the server (MCP_LETTER_DIR) does not exist.');
        }

        $candidate = str_starts_with($path, '/') ? $path : $root.'/'.$path;
        $real = '' === trim($path) ? false : realpath($candidate);
        if (false === $real || !str_starts_with($real, $root.\DIRECTORY_SEPARATOR) || !is_file($real)) {
            throw new LetterNotAllowedException('No such letter in the letter folder. Pass a file name relative to that folder.');
        }

        if (!\in_array(strtolower(pathinfo($real, \PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
            throw new LetterNotAllowedException(\sprintf('Only %s files can be explained.', strtoupper(implode(', ', self::EXTENSIONS))));
        }

        return $real;
    }
}
