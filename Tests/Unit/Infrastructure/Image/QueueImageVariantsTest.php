<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Tests\Unit\Infrastructure\Image;

use Koh\Typo3Async\Domain\AsyncMode;
use Koh\Typo3Async\Infrastructure\Image\ImageProfiles;
use Koh\Typo3Async\Infrastructure\Image\PregenerateImageVariants;
use Koh\Typo3Async\Infrastructure\Image\QueueImageVariants;
use Koh\Typo3Async\Tests\Unit\Infrastructure\Mail\RecordingBus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class QueueImageVariantsTest extends TestCase
{
    private const REFERENCE_PROFILES = '[{"width":320,"crop":"default"}]';

    #[Test]
    public function aCroppedReferenceIsQueuedInAsyncMode(): void
    {
        $bus = new RecordingBus();

        new QueueImageVariants($bus, new AsyncMode(true), ImageProfiles::fromConfiguration(self::REFERENCE_PROFILES))->queueReference(7);

        self::assertEquals([PregenerateImageVariants::forReference(7)], $bus->dispatched);
    }

    #[Test]
    public function learningQueuesACropWithoutAnyListedProfile(): void
    {
        $bus = new RecordingBus();

        new QueueImageVariants($bus, new AsyncMode(true), ImageProfiles::fromConfiguration('', 10))->queueReference(7);

        self::assertCount(1, $bus->dispatched, 'which profiles apply is decided in the consumer');
    }

    #[Test]
    public function nothingIsQueuedWithoutAConsumerOrWithoutAMatchingProfile(): void
    {
        foreach ([
            'async off' => [false, self::REFERENCE_PROFILES, 7],
            'file profiles only' => [true, '[{"width":320}]', 7],
            'no profiles' => [true, '', 7],
            'unresolved NEW id' => [true, self::REFERENCE_PROFILES, 0],
        ] as $case => [$async, $profiles, $uid]) {
            $bus = new RecordingBus();

            new QueueImageVariants($bus, new AsyncMode($async), ImageProfiles::fromConfiguration($profiles))->queueReference($uid);

            self::assertSame([], $bus->dispatched, $case);
        }
    }
}
