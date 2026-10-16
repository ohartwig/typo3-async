<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Tests\Unit\Infrastructure\Image;

use Koh\Typo3Async\Domain\AsyncMode;
use Koh\Typo3Async\Domain\UploadContext;
use Koh\Typo3Async\Infrastructure\Image\ImageProfiles;
use Koh\Typo3Async\Infrastructure\Image\PregenerateImageVariants;
use Koh\Typo3Async\Infrastructure\Image\QueueImageVariants;
use Koh\Typo3Async\Infrastructure\Image\UploadPolicy;
use Koh\Typo3Async\Tests\Unit\Infrastructure\Mail\RecordingBus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileType;
use TYPO3\CMS\Core\Resource\ResourceStorage;

final class QueueImageVariantsTest extends TestCase
{
    private const REFERENCE_PROFILES = '[{"width":320,"crop":"default"}]';
    private const FILE_PROFILES = '[{"width":320}]';

    private static function context(bool $backend): UploadContext
    {
        return new class ($backend) implements UploadContext {
            public function __construct(private readonly bool $backend) {}

            public function isBackend(): bool
            {
                return $this->backend;
            }
        };
    }

    private function image(int $width, int $height): File
    {
        return new File(
            ['uid' => 5, 'name' => 'photo.jpg', 'identifier' => '/photo.jpg', 'type' => FileType::IMAGE->value],
            self::createStub(ResourceStorage::class),
            ['width' => $width, 'height' => $height],
        );
    }

    private static function queue(RecordingBus $bus, UploadPolicy $policy, bool $backend, ?AbstractLogger $logger = null): QueueImageVariants
    {
        return new QueueImageVariants(
            $bus,
            new AsyncMode(true),
            ImageProfiles::fromConfiguration(self::FILE_PROFILES),
            $policy,
            self::context($backend),
            $logger ?? new NullLogger(),
        );
    }

    #[Test]
    public function anEditorsUploadIsQueued(): void
    {
        $bus = new RecordingBus();

        self::queue($bus, new UploadPolicy(), backend: true)->queueFile($this->image(4000, 3000));

        self::assertEquals([PregenerateImageVariants::forFile(5)], $bus->dispatched);
    }

    #[Test]
    public function anUploadFromOutsideTheBackendIsNotQueuedUnlessOptedIn(): void
    {
        $bus = new RecordingBus();
        self::queue($bus, new UploadPolicy(), backend: false)->queueFile($this->image(4000, 3000));
        self::assertSame([], $bus->dispatched, 'a form upload is nobody\'s render request');

        self::queue($bus, new UploadPolicy(queueFrontendUploads: true), backend: false)->queueFile($this->image(4000, 3000));
        self::assertCount(1, $bus->dispatched, 'unless the site says its templates render them');
    }

    #[Test]
    public function anImageOverThePixelLimitIsLeftToTheFirstViewAndLogged(): void
    {
        $bus = new RecordingBus();
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = $level . ': ' . strtr((string) $message, array_combine(
                    array_map(static fn(string $k): string => '{' . $k . '}', array_keys($context)),
                    array_map(strval(...), $context),
                ));
            }
        };

        self::queue($bus, new UploadPolicy(maxMegapixels: 12), backend: true, logger: $logger)->queueFile($this->image(4000, 3001));

        self::assertSame([], $bus->dispatched);
        self::assertSame(['warning: Variants of file 5 not queued: 4000 x 3001 exceeds 12 megapixels'], $logger->lines);
    }

    #[Test]
    public function unknownDimensionsAreNotHeldAgainstAnUpload(): void
    {
        $bus = new RecordingBus();

        self::queue($bus, new UploadPolicy(maxMegapixels: 1), backend: true)->queueFile($this->image(0, 0));

        self::assertCount(1, $bus->dispatched, 'the indexer could not read them; that is no size');
    }

    #[Test]
    public function aCroppedReferenceIsQueuedInAsyncMode(): void
    {
        $bus = new RecordingBus();

        new QueueImageVariants($bus, new AsyncMode(true), ImageProfiles::fromConfiguration(self::REFERENCE_PROFILES), new UploadPolicy(), self::context(true), new NullLogger())->queueReference(7);

        self::assertEquals([PregenerateImageVariants::forReference(7)], $bus->dispatched);
    }

    #[Test]
    public function learningQueuesACropWithoutAnyListedProfile(): void
    {
        $bus = new RecordingBus();

        new QueueImageVariants($bus, new AsyncMode(true), ImageProfiles::fromConfiguration('', 10), new UploadPolicy(), self::context(true), new NullLogger())->queueReference(7);

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

            new QueueImageVariants($bus, new AsyncMode($async), ImageProfiles::fromConfiguration($profiles), new UploadPolicy(), self::context(true), new NullLogger())->queueReference($uid);

            self::assertSame([], $bus->dispatched, $case);
        }
    }
}
