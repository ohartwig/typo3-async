<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Infrastructure\Image;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues one batch of a backfill pass and the message for the next batch.
 *
 * Batches keep the `images` queue short. The consumer reads its queues in a
 * fixed order and serves a later queue only while the earlier ones are
 * empty, so a pass queued in one go would hold everything behind `images`
 * for as long as it takes to work it off, and it would trip the backlog
 * alert (QueueBacklog, oldest due message over 15 minutes). One batch of
 * `$batchSize` images, then this message again at the end of the queue: the
 * queue never holds more than a batch, and whatever arrives meanwhile in the
 * same queue -- an upload, a crop -- waits at most for that batch.
 *
 * A message of a pass that is no longer the current one is dropped: the
 * newer pass covers the same images with the newer profiles.
 */
final class BackfillImageVariantsHandler
{
    public const DEFAULT_BATCH_SIZE = 50;

    public function __construct(
        private readonly ImagesInUse $images,
        private readonly ImageVariantsBackfill $backfill,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        private readonly int $batchSize = self::DEFAULT_BATCH_SIZE,
    ) {}

    public function __invoke(BackfillImageVariants $message): void
    {
        if ($message->pass !== $this->backfill->currentPass()) {
            $this->logger->info('Backfill pass {pass} was replaced, stopping it', ['pass' => $message->pass]);

            return;
        }
        $files = BackfillImageVariants::PHASE_FILES === $message->phase;
        $batch = $files
            ? $this->images->filesAfter($message->afterUid, $this->batchSize)
            : $this->images->croppedReferencesAfter($message->afterUid, $this->batchSize);

        foreach ($batch->uids as $uid) {
            $this->bus->dispatch($files ? PregenerateImageVariants::forFile($uid) : PregenerateImageVariants::forReference($uid));
        }

        if (null !== $batch->lastUid) {
            $this->bus->dispatch($message->continueAfter($batch->lastUid));

            return;
        }
        if ($files) {
            $this->bus->dispatch($message->withReferences());

            return;
        }
        $this->logger->info('Backfill pass {pass} queued every image in use', ['pass' => $message->pass]);
    }
}
