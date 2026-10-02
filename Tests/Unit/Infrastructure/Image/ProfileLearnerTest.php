<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Tests\Unit\Infrastructure\Image;

use Koh\Typo3Async\Infrastructure\Image\ProfileLearner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Imaging\ImageManipulation\Area;

final class ProfileLearnerTest extends TestCase
{
    /**
     * @param array<string, mixed> $configuration
     *
     * @return array{original: int, configuration: string}
     */
    private static function row(int $original, array $configuration): array
    {
        return ['original' => $original, 'configuration' => serialize($configuration)];
    }

    /**
     * @return \Closure(int, Area): list<string>
     */
    private static function noVariants(): \Closure
    {
        return static fn(int $original, Area $area): array => [];
    }

    #[Test]
    public function aConfigurationUsedForEnoughOriginalsIsLearnedKeysInStoredOrder(): void
    {
        $srcset = ['width' => 640, 'crop' => null, 'fileExtension' => 'avif'];
        $rows = [];
        foreach (range(1, 8) as $original) {
            $rows[] = self::row($original, $srcset);
        }
        $rows[] = self::row(9, ['width' => 12]);
        $rows[] = self::row(10, ['width' => 12]);

        $learned = new ProfileLearner()->learn($rows, self::noVariants(), 0.1, 3);

        self::assertCount(1, $learned, 'width 12: two originals, under the minimum of three');
        self::assertSame($srcset, $learned[0]['profile']);
        self::assertSame(['width', 'crop', 'fileExtension'], array_keys($learned[0]['profile']));
        self::assertSame(8, $learned[0]['originals']);
        self::assertEqualsWithDelta(0.8, $learned[0]['share'], 0.001);
    }

    #[Test]
    public function aShareBelowTheThresholdIsNotLearned(): void
    {
        $rows = [self::row(1, ['width' => 64]), self::row(2, ['width' => 64]), self::row(3, ['width' => 64])];
        foreach (range(4, 40) as $original) {
            $rows[] = self::row($original, ['width' => 640]);
        }

        $learned = new ProfileLearner()->learn($rows, self::noVariants(), 0.1, 3);

        self::assertSame([['width' => 640]], array_column($learned, 'profile'), '3 of 40 is 7.5 %');
    }

    #[Test]
    public function aCropAreaIsLearnedAsTheVariantThatProducesItInPlace(): void
    {
        $rows = [];
        foreach (range(1, 4) as $original) {
            $rows[] = self::row($original, ['width' => 960, 'crop' => new Area(10, 0, 500, 300), 'fileExtension' => 'webp']);
        }
        $variantsOf = static fn(int $original, Area $area): array => 4 === $original ? [] : ['hero'];

        $learned = new ProfileLearner()->learn($rows, $variantsOf, 0.1, 3);

        self::assertSame([['width' => 960, 'crop' => 'hero', 'fileExtension' => 'webp']], array_column($learned, 'profile'));
        self::assertSame(3, $learned[0]['originals'], 'original 4: no variant produces the area any more');
    }

    #[Test]
    public function twoVariantsWithTheSameAreaAreBothCredited(): void
    {
        $rows = [];
        foreach (range(1, 3) as $original) {
            $rows[] = self::row($original, ['width' => 320, 'crop' => new Area(0, 0, 100, 100)]);
        }

        $learned = new ProfileLearner()->learn($rows, static fn(int $o, Area $a): array => ['sm', 'xs'], 0.1, 3);

        self::assertSame([['width' => 320, 'crop' => 'sm'], ['width' => 320, 'crop' => 'xs']], array_column($learned, 'profile'));
    }

    #[Test]
    public function garbageRowsTeachNothing(): void
    {
        $rows = [
            ['original' => 1, 'configuration' => 'garbage'],
            ['original' => 2, 'configuration' => null],
            ['original' => 0, 'configuration' => serialize(['width' => 1])],
            self::row(3, []),
            self::row(4, ['nested' => ['x']]),
        ];

        self::assertSame([], new ProfileLearner()->learn($rows, self::noVariants(), 0.0, 1));
    }
}
