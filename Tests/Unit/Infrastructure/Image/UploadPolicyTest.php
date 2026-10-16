<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Tests\Unit\Infrastructure\Image;

use Koh\Typo3Async\Infrastructure\Image\UploadPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UploadPolicyTest extends TestCase
{
    #[Test]
    public function byDefaultOnlyBackendUploadsAreQueued(): void
    {
        $policy = UploadPolicy::fromConfiguration(null, null);

        self::assertTrue($policy->allowsContext(true));
        self::assertFalse($policy->allowsContext(false));
        self::assertSame(UploadPolicy::DEFAULT_MAX_MEGAPIXELS, $policy->maxMegapixels());
    }

    #[Test]
    public function frontendUploadsCanBeOptedIn(): void
    {
        foreach (['1', 'true', true, 1] as $on) {
            self::assertTrue(UploadPolicy::fromConfiguration($on, null)->allowsContext(false), var_export($on, true));
        }
        foreach (['0', 'false', false, '', 'maybe', null] as $off) {
            self::assertFalse(UploadPolicy::fromConfiguration($off, null)->allowsContext(false), var_export($off, true));
        }
    }

    #[Test]
    public function thePixelLimitIsCountedInMegapixelsAndZeroMeansNone(): void
    {
        $two = UploadPolicy::fromConfiguration(false, '2');

        self::assertTrue($two->allowsDimensions(2000, 1000), 'exactly 2 MP');
        self::assertFalse($two->allowsDimensions(2000, 1001), 'one row over');
        self::assertTrue($two->allowsDimensions(0, 9999), 'unknown width: the indexer could not read it');
        self::assertTrue($two->allowsDimensions(9999, 0), 'unknown height');
        self::assertTrue(UploadPolicy::fromConfiguration(false, 0)->allowsDimensions(100000, 100000), '0 = no limit');
        self::assertSame(UploadPolicy::DEFAULT_MAX_MEGAPIXELS, UploadPolicy::fromConfiguration(false, 'lots')->maxMegapixels(), 'not a number: default');
        self::assertSame(0, UploadPolicy::fromConfiguration(false, -5)->maxMegapixels(), 'negative: treated as no limit');
    }
}
