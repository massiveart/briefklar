<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Bridge\SymfonyAi;

/**
 * Applies a string transformation to every string inside arrays and objects (structured output DTOs).
 *
 * Objects of user classes are copied (readonly properties included) instead of mutated; objects
 * without any changed string are returned as they are. Enums, dates and other internal classes
 * (except stdClass) are left untouched.
 *
 * @internal
 */
final class StructuredRestorer
{
    /** @var \SplObjectStorage<object, object> */
    private \SplObjectStorage $copies;

    /**
     * @param \Closure(string): string $restore
     */
    public function __construct(
        private readonly \Closure $restore,
    ) {
        $this->copies = new \SplObjectStorage();
    }

    public function restore(mixed $value): mixed
    {
        return match (true) {
            \is_string($value) => ($this->restore)($value),
            \is_array($value) => array_map($this->restore(...), $value),
            \is_object($value) => $this->restoreObject($value),
            default => $value,
        };
    }

    private function restoreObject(object $object): object
    {
        if (isset($this->copies[$object])) {
            return $this->copies[$object];
        }

        $class = new \ReflectionClass($object);
        if ($object instanceof \UnitEnum || ($class->isInternal() && !$object instanceof \stdClass)) {
            return $object;
        }

        if ($object instanceof \stdClass) {
            $copy = new \stdClass();
            $this->copies[$object] = $copy;
            $changed = false;
            foreach (get_object_vars($object) as $name => $value) {
                $copy->{$name} = $this->restore($value);
                $changed = $changed || $copy->{$name} !== $value;
            }

            return $changed ? $copy : $object;
        }

        $copy = $class->newInstanceWithoutConstructor();
        $this->copies[$object] = $copy;
        $changed = false;

        for ($current = $class; false !== $current; $current = $current->getParentClass()) {
            foreach ($current->getProperties() as $property) {
                if ($property->isStatic() || $property->isVirtual() || $property->getDeclaringClass()->getName() !== $current->getName()) {
                    continue;
                }
                if (!$property->isInitialized($object)) {
                    continue;
                }
                $original = $property->getValue($object);
                $restored = $this->restore($original);
                $changed = $changed || $restored !== $original;

                // Initialising (also readonly) properties is allowed from the declaring class scope.
                \Closure::bind(static fn () => $property->setValue($copy, $restored), null, $current->getName())();
            }
        }

        return $changed ? $copy : $object;
    }
}
