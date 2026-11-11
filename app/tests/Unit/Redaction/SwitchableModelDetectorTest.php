<?php

declare(strict_types=1);

namespace App\Tests\Unit\Redaction;

use App\Redaction\SwitchableModelDetector;
use Briefklar\PiiShield\Detector\DetectorInterface;
use Briefklar\PiiShield\Entity;
use Briefklar\PiiShield\EntityType;
use Briefklar\PiiShield\Exception\DetectionFailedException;
use PHPUnit\Framework\TestCase;

final class SwitchableModelDetectorTest extends TestCase
{
    public function testOffDoesNotAskTheModel(): void
    {
        $model = $this->createMock(DetectorInterface::class);
        $model->expects(self::never())->method('detect');

        self::assertSame([], (new SwitchableModelDetector($model, false))->detect('Lukas Testmann'));
    }

    public function testOnReturnsWhatTheModelFound(): void
    {
        $found = [new Entity(EntityType::Person, 'Lukas Testmann', 0, 0.85, 'model')];
        $model = self::createStub(DetectorInterface::class);
        $model->method('detect')->willReturn($found);

        self::assertSame($found, (new SwitchableModelDetector($model, true))->detect('Lukas Testmann'));
    }

    public function testOnLetsAFailingModelFailClosed(): void
    {
        $model = self::createStub(DetectorInterface::class);
        $model->method('detect')->willThrowException(new DetectionFailedException('unreachable'));

        $this->expectException(DetectionFailedException::class);
        (new SwitchableModelDetector($model, true))->detect('Lukas Testmann');
    }
}
