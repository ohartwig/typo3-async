<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Infrastructure\Image;

/**
 * One read of ImagesInUse: the uids to queue, and the last uid looked at --
 * the cursor for the next read, which may lie past the last uid queued when
 * the pixel limit left rows out. `$lastUid` is null when the read found
 * nothing, which ends the phase.
 */
final class ImageBatch
{
    /**
     * @param list<int> $uids
     */
    public function __construct(
        public readonly array $uids,
        public readonly ?int $lastUid,
    ) {}
}
