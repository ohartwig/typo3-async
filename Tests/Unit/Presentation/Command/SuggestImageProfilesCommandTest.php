<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Tests\Unit\Presentation\Command;

use Koh\Typo3Async\Presentation\Command\SuggestImageProfilesCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Imaging\ImageManipulation\Area;

final class SuggestImageProfilesCommandTest extends TestCase
{
    #[Test]
    public function mostFrequentFirstWithKeysInStoredOrder(): void
    {
        $viewHelper = serialize(['width' => 640, 'height' => null, 'maxHeight' => null, 'crop' => null]);
        $short = serialize(['fileExtension' => 'avif', 'width' => 960]);
        $rare = serialize(['width' => 12]);

        $ranked = SuggestImageProfilesCommand::rank([$short, $viewHelper, $viewHelper, $short, $viewHelper, $rare], 2, 10);

        self::assertSame([
            ['profile' => ['width' => 640, 'height' => null, 'maxHeight' => null, 'crop' => null], 'count' => 3],
            ['profile' => ['fileExtension' => 'avif', 'width' => 960], 'count' => 2],
        ], $ranked);
    }

    #[Test]
    public function aStoredCropAreaBecomesTheDefaultVariantInPlace(): void
    {
        $cropped = serialize(['width' => 320, 'crop' => new Area(1, 2, 3, 4), 'fileExtension' => 'webp']);

        $ranked = SuggestImageProfilesCommand::rank([$cropped], 1, 10);

        self::assertSame(['width' => 320, 'crop' => 'default', 'fileExtension' => 'webp'], $ranked[0]['profile']);
    }

    #[Test]
    public function theLimitAndGarbageRowsAreRespected(): void
    {
        $rows = [serialize(['width' => 1]), serialize(['width' => 2]), 'garbage', null, serialize([]), serialize(['nested' => ['x']])];

        self::assertCount(1, SuggestImageProfilesCommand::rank($rows, 1, 1));
        self::assertCount(2, SuggestImageProfilesCommand::rank($rows, 1, 10));
    }
}
