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
}
