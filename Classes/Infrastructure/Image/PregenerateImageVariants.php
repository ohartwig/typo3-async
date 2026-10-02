<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Infrastructure\Image;

/**
 * The message: produce the configured variants of one file, or of one file
 * reference with its crop. Carries uids only -- the consumer reads the record
 * fresh, so a file replaced or a crop changed again before the message is
 * handled is processed in its newest state.
 */
final class PregenerateImageVariants
{
    private function __construct(
        public readonly ?int $fileUid,
        public readonly ?int $referenceUid,
    ) {}

    public static function forFile(int $uid): self
    {
        return new self($uid, null);
    }

    public static function forReference(int $uid): self
    {
        return new self(null, $uid);
    }
}
