<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Infrastructure\Mail;

use Koh\Typo3Async\Domain\AsyncMode;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\RawMessage;
use TYPO3\CMS\Core\Mail\MailerInterface;

/**
 * Decorates TYPO3's mailer: with async mode on, a mail is rendered in the
 * request and handed to the message bus instead of the SMTP server, so a slow
 * or unreachable mail server no longer holds the request (a form submit, a
 * password reset, a backend save). The consumer sends it through the inner
 * mailer (SendEmailHandler), with TYPO3's own events and transport.
 *
 * With async mode off it is the inner mailer, call for call.
 *
 * getSentMessage() is null for a queued mail: nothing has been sent yet when
 * the request ends. That is the honest answer, and callers in the core only use
 * it for logging.
 */
final class AsyncMailer implements MailerInterface
{
    private bool $lastQueued = false;

    public function __construct(
        private readonly MailerInterface $inner,
        private readonly MessageBusInterface $bus,
        private readonly AsyncMode $mode,
    ) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        if (!$this->mode->isEnabled()) {
            $this->lastQueued = false;
            $this->inner->send($message, $envelope);

            return;
        }

        $this->bus->dispatch(new SendEmailMessage(QueueableEmail::from($message), $envelope));
        $this->lastQueued = true;
    }

    public function getSentMessage(): ?SentMessage
    {
        return $this->lastQueued ? null : $this->inner->getSentMessage();
    }

    public function getTransport(): TransportInterface
    {
        return $this->inner->getTransport();
    }

    public function getRealTransport(): TransportInterface
    {
        return $this->inner->getRealTransport();
    }
}
