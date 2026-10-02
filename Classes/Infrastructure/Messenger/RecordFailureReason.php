<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Infrastructure\Messenger;

use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\EventListener\AddErrorDetailsStampListener;
use TYPO3\CMS\Core\Attribute\AsEventListener;

/**
 * Writes the exception onto a failed message before it is retried or parked,
 * so a message in the failure queue says why it is there. Another of
 * Symfony's worker listeners that TYPO3 does not register on its own.
 */
final class RecordFailureReason
{
    private readonly AddErrorDetailsStampListener $listener;

    public function __construct()
    {
        $this->listener = new AddErrorDetailsStampListener();
    }

    #[AsEventListener(identifier: 'koh-async/record-failure-reason', before: 'koh-async/retry-failed-message')]
    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $this->listener->onMessageFailed($event);
    }
}
