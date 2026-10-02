<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Infrastructure\Messenger;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use TYPO3\CMS\Core\Attribute\AsEventListener;

/**
 * Puts a failed message back on its transport, delayed, up to the transport's
 * retry limit.
 *
 * TYPO3's messenger:consume hands the worker TYPO3's event dispatcher, and the
 * core registers no retry: without this listener a message whose handler
 * throws is acknowledged and gone. For a mail that means an SMTP hiccup loses
 * the mail. Symfony's own listener does the work; this class only connects it
 * to TYPO3's PSR-14 dispatcher.
 */
final class RetryFailedMessage
{
    private readonly SendFailedMessageForRetryListener $listener;

    public function __construct(
        ContainerInterface $sendersLocator,
        ContainerInterface $retryStrategyLocator,
        LoggerInterface $logger,
    ) {
        $this->listener = new SendFailedMessageForRetryListener($sendersLocator, $retryStrategyLocator, $logger);
    }

    #[AsEventListener(identifier: 'koh-async/retry-failed-message', before: 'koh-async/park-failed-message')]
    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $this->listener->onMessageFailed($event);
    }
}
