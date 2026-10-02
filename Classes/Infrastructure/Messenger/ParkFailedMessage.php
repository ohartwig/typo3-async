<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Infrastructure\Messenger;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use TYPO3\CMS\Core\Attribute\AsEventListener;

/**
 * Moves a message that has used up its retries into the failure queue, where
 * it waits to be looked at instead of being dropped. Runs after
 * RetryFailedMessage and does nothing while a retry is still due.
 */
final class ParkFailedMessage
{
    private readonly SendFailedMessageToFailureTransportListener $listener;

    public function __construct(ContainerInterface $failureSenders, LoggerInterface $logger)
    {
        $this->listener = new SendFailedMessageToFailureTransportListener($failureSenders, $logger);
    }

    #[AsEventListener(identifier: 'koh-async/park-failed-message')]
    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $this->listener->onMessageFailed($event);
    }
}
