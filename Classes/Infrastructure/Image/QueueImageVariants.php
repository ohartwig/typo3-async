<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Infrastructure\Image;

use Koh\Typo3Async\Domain\AsyncMode;
use Symfony\Component\Messenger\MessageBusInterface;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Resource\Event\AfterFileAddedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileReplacedEvent;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\FileType;

/**
 * Queues the variants of an image right after it is uploaded or replaced.
 *
 * Only in async mode, and never by processing in place: producing ten
 * variants inside the upload request would make the editor wait for exactly
 * the work this exists to move out of the request.
 */
final class QueueImageVariants
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly AsyncMode $mode,
        private readonly ImageProfiles $profiles,
    ) {}

    #[AsEventListener(identifier: 'koh-async/queue-image-variants-added')]
    public function afterAdded(AfterFileAddedEvent $event): void
    {
        $this->queueFile($event->getFile());
    }

    #[AsEventListener(identifier: 'koh-async/queue-image-variants-replaced')]
    public function afterReplaced(AfterFileReplacedEvent $event): void
    {
        $this->queueFile($event->getFile());
    }

    public function queueFile(FileInterface $file): void
    {
        if (!$this->mode->isEnabled() || [] === $this->profiles->forFiles()) {
            return;
        }
        if (!$file instanceof File || !$file->isType(FileType::IMAGE)) {
            return;
        }
        $this->bus->dispatch(PregenerateImageVariants::forFile($file->getUid()));
    }

    public function queueReference(int $uid): void
    {
        if (!$this->mode->isEnabled() || !$this->profiles->hasReferenceProfiles() || $uid <= 0) {
            return;
        }
        $this->bus->dispatch(PregenerateImageVariants::forReference($uid));
    }
}
