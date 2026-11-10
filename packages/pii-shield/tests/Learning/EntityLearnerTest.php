<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Tests\Learning;

use Briefklar\PiiShield\Exception\LearningFailedException;
use Briefklar\PiiShield\Learning\CacheCustomEntityStore;
use Briefklar\PiiShield\Learning\CustomEntityStoreInterface;
use Briefklar\PiiShield\Learning\CustomEntityType;
use Briefklar\PiiShield\Learning\EntityLearner;
use Briefklar\PiiShield\Learning\JsonFileCustomEntityStore;
use Briefklar\PiiShield\PiiShield;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class EntityLearnerTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir().'/pii-shield-learned-'.bin2hex(random_bytes(4)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    /**
     * @return iterable<string, array{\Closure(string): CustomEntityStoreInterface}>
     */
    public static function storeProvider(): iterable
    {
        yield 'json file' => [static fn (string $file): CustomEntityStoreInterface => new JsonFileCustomEntityStore($file)];
        $pool = new ArrayAdapter();
        yield 'psr-6 cache' => [static fn (string $file): CustomEntityStoreInterface => new CacheCustomEntityStore($pool)];
    }

    /**
     * @param \Closure(string): CustomEntityStoreInterface $store
     */
    #[DataProvider('storeProvider')]
    public function testStoresSavesReplacesAndRemoves(\Closure $store): void
    {
        $a = new CustomEntityType('INSURANCE_NUMBER', 'Versichertennummer', ['VN-2026-000417'], [], 'VN-\d{4}-\d{6}');
        $b = new CustomEntityType('PET_NAME', 'Haustier', ['Bello']);

        $store($this->file)->save($a);
        $store($this->file)->save($b);
        $store($this->file)->save(new CustomEntityType('INSURANCE_NUMBER', 'geändert', ['VN-2026-000417'], [], 'VN-\d{4}-\d{6}'));

        self::assertSame(['INSURANCE_NUMBER', 'PET_NAME'], array_map(static fn (CustomEntityType $t): string => $t->name, $store($this->file)->all()));
        self::assertSame('geändert', $store($this->file)->get('INSURANCE_NUMBER')?->description);

        $store($this->file)->remove('PET_NAME');
        self::assertNull($store($this->file)->get('PET_NAME'));
    }

    public function testLearnsAPatternFromExamplesAndSavesIt(): void
    {
        $learner = new EntityLearner(new JsonFileCustomEntityStore($this->file));

        $result = $learner->learn('INSURANCE_NUMBER', 'Versichertennummer', ['VN-2026-000417', 'VN-2025-998812']);

        self::assertSame('VN-\d{4}-\d{6}', $result->type->pattern);
        self::assertNull($result->note);
        self::assertNotNull((new JsonFileCustomEntityStore($this->file))->get('INSURANCE_NUMBER'));
    }

    public function testWordsWithoutAPatternBecomeModelOnlyTypes(): void
    {
        $learner = new EntityLearner(new JsonFileCustomEntityStore($this->file));

        $result = $learner->learn('PET_NAME', 'Name eines Haustiers', ['Bello', 'Minka']);

        self::assertNull($result->type->pattern);
        self::assertNotNull($result->note);
    }

    public function testAnExplicitPatternWins(): void
    {
        $learner = new EntityLearner(new JsonFileCustomEntityStore($this->file));

        $result = $learner->learn('INSURANCE_NUMBER', 'x', ['VN-2026-000417'], pattern: 'VN-\d{4}-\d{6}');

        self::assertSame('VN-\d{4}-\d{6}', $result->type->pattern);
    }

    public function testPreviewDoesNotSave(): void
    {
        $learner = new EntityLearner(new JsonFileCustomEntityStore($this->file));

        $learner->preview('INSURANCE_NUMBER', 'x', ['VN-2026-000417']);

        self::assertSame([], $learner->types());
    }

    public function testConflictsWithCounterExamplesAreReported(): void
    {
        $learner = new EntityLearner(new JsonFileCustomEntityStore($this->file));

        $this->expectException(LearningFailedException::class);

        $learner->learn('INSURANCE_NUMBER', 'x', ['VN-2026-000417'], ['VN-2026-999999']);
    }

    public function testLearnedTypesAreDetectedRightAway(): void
    {
        $learner = new EntityLearner(new JsonFileCustomEntityStore($this->file));
        $shield = new PiiShield([$learner->detector()]);

        self::assertSame('Nr. VN-2026-000417', $shield->redact('Nr. VN-2026-000417')->redactedText);

        $learner->learn('INSURANCE_NUMBER', 'x', ['VN-2026-000417']);

        self::assertSame('Nr. ⟦INSURANCE_NUMBER_1⟧', $shield->redact('Nr. VN-2026-000417')->redactedText);

        $learner->forget('INSURANCE_NUMBER');

        self::assertSame('Nr. VN-2026-000417', $shield->redact('Nr. VN-2026-000417')->redactedText);
    }

    public function testJsonFileIsNotWorldReadable(): void
    {
        (new JsonFileCustomEntityStore($this->file))->save(new CustomEntityType('PET_NAME', 'x', ['Bello']));

        self::assertSame('0600', substr(\sprintf('%o', fileperms($this->file)), -4));
    }
}
