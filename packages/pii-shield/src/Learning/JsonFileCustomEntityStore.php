<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Learning;

/**
 * Learned types in one JSON file: readable, diffable, easy to review before deploying.
 * Writes are atomic (temporary file + rename); the file is created with mode 0600.
 */
final class JsonFileCustomEntityStore implements CustomEntityStoreInterface
{
    public function __construct(
        private readonly string $path,
    ) {
    }

    public function all(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($this->path), true);
        if (!\is_array($data)) {
            throw new \RuntimeException(\sprintf('"%s" does not contain a list of learned types.', $this->path));
        }

        $types = [];
        foreach ($data as $entry) {
            if (\is_array($entry)) {
                $types[] = CustomEntityType::fromArray($entry);
            }
        }
        usort($types, static fn (CustomEntityType $a, CustomEntityType $b): int => strcmp($a->name, $b->name));

        return $types;
    }

    public function get(string $name): ?CustomEntityType
    {
        foreach ($this->all() as $type) {
            if ($type->name === $name) {
                return $type;
            }
        }

        return null;
    }

    public function save(CustomEntityType $type): void
    {
        $types = array_filter($this->all(), static fn (CustomEntityType $t): bool => $t->name !== $type->name);
        $this->write([...$types, $type]);
    }

    public function remove(string $name): void
    {
        $this->write(array_filter($this->all(), static fn (CustomEntityType $t): bool => $t->name !== $name));
    }

    /**
     * @param array<CustomEntityType> $types
     */
    private function write(array $types): void
    {
        usort($types, static fn (CustomEntityType $a, CustomEntityType $b): int => strcmp($a->name, $b->name));
        $json = json_encode(array_map(static fn (CustomEntityType $t): array => $t->toArray(), $types), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        $dir = \dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $tmp = tempnam($dir, '.learned-');
        if (false === $tmp) {
            throw new \RuntimeException(\sprintf('Cannot write to "%s".', $dir));
        }
        chmod($tmp, 0600);
        file_put_contents($tmp, $json."\n");
        rename($tmp, $this->path);
    }
}
