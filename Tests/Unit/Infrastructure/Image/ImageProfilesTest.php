<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Tests\Unit\Infrastructure\Image;

use Koh\Typo3Async\Infrastructure\Image\ImageProfiles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ImageProfilesTest extends TestCase
{
    #[Test]
    public function anythingButAListOfObjectsMeansOff(): void
    {
        foreach (['', '   ', 'not json', '{"width":640}', '[1,2]', '[[640]]', null, 42] as $raw) {
            self::assertTrue(ImageProfiles::fromConfiguration($raw)->isEmpty(), var_export($raw, true));
        }
    }

    #[Test]
    public function aStringCropMakesAReferenceProfileAndANullCropAFileProfile(): void
    {
        $profiles = ImageProfiles::fromConfiguration(
            '[{"width":640},{"width":1280,"crop":null},{"width":320,"crop":"default"},{"width":99,"crop":7}]'
        );

        self::assertSame([['width' => 640], ['width' => 1280, 'crop' => null]], $profiles->forFiles());
        self::assertSame([['width' => 320, 'crop' => 'default']], $profiles->forReferences());
        self::assertTrue($profiles->hasReferenceProfiles());
    }

    #[Test]
    public function keysKeepTheirOrderBecauseTheChecksumDoes(): void
    {
        $profiles = ImageProfiles::fromConfiguration('[{"maxHeight":45,"height":"45m","maxWidth":60}]');

        self::assertSame(['maxHeight', 'height', 'maxWidth'], array_keys($profiles->forFiles()[0]));
    }

    #[Test]
    public function learningIsOnAtTenPercentUnlessSaidOtherwise(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['koh_async'] = [];
        self::assertEqualsWithDelta(0.1, ImageProfiles::fromGlobals()->learnShare(), 0.0001);
        self::assertTrue(ImageProfiles::fromGlobals()->queuesFiles());
        self::assertTrue(ImageProfiles::fromGlobals()->queuesReferences());

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['koh_async'] = ['learnImageProfiles' => 0];
        self::assertFalse(ImageProfiles::fromGlobals()->learns());
        self::assertFalse(ImageProfiles::fromGlobals()->queuesFiles(), 'off and nothing listed: no messages');

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['koh_async'] = ['learnImageProfiles' => '25', 'imageProfiles' => '[{"width":1}]'];
        self::assertEqualsWithDelta(0.25, ImageProfiles::fromGlobals()->learnShare(), 0.0001);

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['koh_async'] = ['learnImageProfiles' => 'lots'];
        self::assertEqualsWithDelta(0.1, ImageProfiles::fromGlobals()->learnShare(), 0.0001, 'garbage: the default, not off');
        unset($GLOBALS['TYPO3_CONF_VARS']);
    }

    #[Test]
    public function listedAndLearnedProfilesAreMergedEachOnce(): void
    {
        $profiles = ImageProfiles::fromConfiguration('[{"width":640}]', 10)
            ->withLearned([['width' => 640], ['width' => 960], ['width' => 320, 'crop' => 'hero']]);

        self::assertSame([['width' => 640], ['width' => 960]], $profiles->forFiles());
        self::assertSame([['width' => 320, 'crop' => 'hero']], $profiles->forReferences());
    }
}
