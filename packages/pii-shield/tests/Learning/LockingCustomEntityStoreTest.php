<?php

declare(strict_types=1);

namespace Clarifile\PiiShield\Tests\Learning;

use Clarifile\PiiShield\Learning\CustomEntityStoreInterface;
use Clarifile\PiiShield\Learning\CustomEntityType;
use Clarifile\PiiShield\Learning\JsonFileCustomEntityStore;
use Clarifile\PiiShield\Learning\LockingCustomEntityStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class LockingCustomEntityStoreTest extends TestCase
{
    private InMemoryStore $locks;

    protected function setUp(): void
    {
        $this->locks = new InMemoryStore();
    }

    private static function type(string $name): CustomEntityType
    {
        return new CustomEntityType($name, 'Versichertennummer einer Versicherung', ['VN-2026-000417'], [], 'VN-\d{4}-\d{6}');
    }

    /**
     * True if another process could take the store's lock right now.
     */
    private function lockIsFree(): bool
    {
        $lock = (new LockFactory($this->locks))->createLock(LockingCustomEntityStore::DEFAULT_RESOURCE);
        $free = $lock->acquire();
        if ($free) {
            $lock->release();
        }

        return $free;
    }

    /**
     * @param \Closure(): void                $onWrite runs inside the inner store's save() and remove()
     * @param array<string, CustomEntityType> $types   initial content
     */
    private function innerStore(\Closure $onWrite, array $types = []): CustomEntityStoreInterface
    {
        return new class($onWrite, $types) implements CustomEntityStoreInterface {
            /**
             * @param array<string, CustomEntityType> $types
             */
            public function __construct(private readonly \Closure $onWrite, private array $types)
            {
            }

            public function all(): array
            {
                return array_values($this->types);
            }

            public function get(string $name): ?CustomEntityType
            {
                return $this->types[$name] ?? null;
            }

            public function save(CustomEntityType $type): void
            {
                ($this->onWrite)();
                $this->types[$type->name] = $type;
            }

            public function remove(string $name): void
            {
                ($this->onWrite)();
                unset($this->types[$name]);
            }
        };
    }

    public function testSaveAndRemoveHoldTheLockWhileTheInnerStoreWrites(): void
    {
        $heldDuringWrite = [];
        $inner = $this->innerStore(function () use (&$heldDuringWrite): void {
            $heldDuringWrite[] = !$this->lockIsFree();
        });
        $store = new LockingCustomEntityStore($inner, new LockFactory($this->locks));

        $store->save(self::type('INSURANCE_NUMBER'));
        self::assertTrue($this->lockIsFree());
        $store->remove('INSURANCE_NUMBER');

        self::assertSame([true, true], $heldDuringWrite);
        self::assertTrue($this->lockIsFree());
        self::assertNull($store->get('INSURANCE_NUMBER'));
    }

    public function testTheLockIsReleasedWhenTheInnerStoreFails(): void
    {
        $inner = $this->innerStore(static function (): void {
            throw new \RuntimeException('disk full');
        });
        $store = new LockingCustomEntityStore($inner, new LockFactory($this->locks));

        try {
            $store->save(self::type('INSURANCE_NUMBER'));
            self::fail('The inner exception must reach the caller.');
        } catch (\RuntimeException $e) {
            self::assertSame('disk full', $e->getMessage());
        }

        self::assertTrue($this->lockIsFree());
    }

    public function testReadsDelegateWithoutTakingTheLock(): void
    {
        $inner = $this->innerStore(static function (): void {
        }, ['INSURANCE_NUMBER' => self::type('INSURANCE_NUMBER')]);
        $holder = (new LockFactory($this->locks))->createLock(LockingCustomEntityStore::DEFAULT_RESOURCE);
        $holder->acquire();
        $store = new LockingCustomEntityStore($inner, new LockFactory($this->locks));

        self::assertCount(1, $store->all());
        self::assertNotNull($store->get('INSURANCE_NUMBER'));
        $holder->release();
    }

    /**
     * Several PHP processes learn different types into one JSON file at the same time. Without the lock, the
     * read-modify-write in save() loses updates; with it, every type ends up in the file.
     */
    public function testConcurrentProcessesDoNotLoseUpdates(): void
    {
        $dir = sys_get_temp_dir().'/pii-shield-lock-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($dir);
        $processes = 8;
        $typesPerProcess = 10;

        try {
            $handles = [];
            for ($p = 0; $p < $processes; ++$p) {
                $command = [\PHP_BINARY, __DIR__.'/Fixtures/learn_concurrently.php', $dir, (string) $p, (string) $typesPerProcess];
                $handle = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($handle);
                $handles[] = [$handle, $pipes];
            }
            foreach ($handles as [$handle, $pipes]) {
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($handle), (string) $errors);
            }

            self::assertCount($processes * $typesPerProcess, (new JsonFileCustomEntityStore($dir.'/learned_types.json'))->all());
        } finally {
            (new Filesystem())->remove($dir);
        }
    }
}
