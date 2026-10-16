<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Infrastructure\Image;

/**
 * Which uploads get their variants queued at all.
 *
 * Every image that enters a storage dispatches the same FAL event, whether an
 * editor uploaded it or a form on the public site saved it into FAL. The
 * consumer would then run every learned profile through ImageMagick for an
 * image nobody will render -- on the same worker that delivers the mail, so
 * a slow or hostile image delays a password reset. Two limits:
 *
 *   queueFrontendUploads  queue uploads made outside the backend (frontend
 *                         forms, scripts without a backend user); default
 *                         false. Switch on only where frontend uploads are
 *                         rendered by templates.
 *   maxUploadMegapixels   largest source image, in megapixels, that is still
 *                         queued; default 50 (an 8000 x 6250 photo), 0 = no
 *                         limit. Larger images are left to the first view.
 *
 * Both under $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['koh_async'], like the
 * profile settings; there is no ext_conf_template.txt, see ExtensionLayoutTest.
 */
final class UploadPolicy
{
    public const DEFAULT_MAX_MEGAPIXELS = 50;

    public function __construct(
        private readonly bool $queueFrontendUploads = false,
        private readonly int $maxMegapixels = self::DEFAULT_MAX_MEGAPIXELS,
    ) {}

    public static function fromConfiguration(mixed $queueFrontendUploads, mixed $maxMegapixels): self
    {
        return new self(
            filter_var($queueFrontendUploads, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? false,
            is_numeric($maxMegapixels) ? max(0, (int) $maxMegapixels) : self::DEFAULT_MAX_MEGAPIXELS,
        );
    }

    public static function fromGlobals(): self
    {
        $settings = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['koh_async'] ?? [];

        return self::fromConfiguration(
            $settings['queueFrontendUploads'] ?? false,
            $settings['maxUploadMegapixels'] ?? self::DEFAULT_MAX_MEGAPIXELS,
        );
    }

    public function allowsContext(bool $backend): bool
    {
        return $backend || $this->queueFrontendUploads;
    }

    /**
     * Unknown dimensions (0) are allowed: the indexer could not read them,
     * which says nothing about the size, and refusing would silently switch
     * pregeneration off for every storage without local metadata.
     */
    public function allowsDimensions(int $width, int $height): bool
    {
        if (0 === $this->maxMegapixels || $width <= 0 || $height <= 0) {
            return true;
        }

        return $width * $height <= $this->maxMegapixels * 1_000_000;
    }

    public function maxMegapixels(): int
    {
        return $this->maxMegapixels;
    }
}
