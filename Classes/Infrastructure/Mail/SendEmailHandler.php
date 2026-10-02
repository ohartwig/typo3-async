<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Infrastructure\Mail;

use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use TYPO3\CMS\Core\Mail\MailerInterface;

/**
 * Sends a queued mail in the consumer.
 *
 * Through the INNER mailer, never the decorated one: the consumer runs with
 * async mode on as well, and the decorator would put the mail straight back on
 * the queue. An exception (SMTP down, address refused) propagates on purpose:
 * the worker then retries the message and finally parks it in the failure
 * queue (RetryFailedMessage, ParkFailedMessage) instead of dropping it.
 */
final class SendEmailHandler
{
    public function __construct(private readonly MailerInterface $mailer) {}

    public function __invoke(SendEmailMessage $message): void
    {
        $this->mailer->send($message->getMessage(), $message->getEnvelope());
    }
}
