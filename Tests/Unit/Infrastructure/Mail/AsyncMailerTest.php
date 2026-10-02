<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Tests\Unit\Infrastructure\Mail;

use Koh\Typo3Async\Domain\AsyncMode;
use Koh\Typo3Async\Infrastructure\Mail\AsyncMailer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Messenger\Envelope as BusEnvelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use TYPO3\CMS\Core\Mail\MailerInterface;

final class AsyncMailerTest extends TestCase
{
    private RecordingMailer $inner;

    private RecordingBus $bus;

    private function mailer(bool $async): AsyncMailer
    {
        $this->inner = new RecordingMailer();
        $this->bus = new RecordingBus();

        return new AsyncMailer($this->inner, $this->bus, new AsyncMode($async));
    }

    private function email(): Email
    {
        return new Email()->from('shop@example.org')->to('customer@example.org')->subject('Hello')->text('Body');
    }

    #[Test]
    public function withAsyncModeOffItSendsInTheRequest(): void
    {
        $mailer = $this->mailer(false);
        $email = $this->email();

        $mailer->send($email);

        self::assertSame([[$email, null]], $this->inner->sent);
        self::assertSame([], $this->bus->dispatched);
    }

    #[Test]
    public function withAsyncModeOnItQueuesTheMailInsteadOfSendingIt(): void
    {
        $mailer = $this->mailer(true);
        $envelope = new Envelope(new Address('bounce@example.org'), [new Address('customer@example.org')]);

        $mailer->send($this->email(), $envelope);

        self::assertSame([], $this->inner->sent, 'nothing reaches the mail server in the request');
        self::assertCount(1, $this->bus->dispatched);
        $queued = $this->bus->dispatched[0];
        self::assertInstanceOf(SendEmailMessage::class, $queued);
        self::assertSame($envelope, $queued->getEnvelope());
        self::assertNull($mailer->getSentMessage(), 'a queued mail has not been sent yet');
    }

    #[Test]
    public function aQueuedMailSurvivesSerialization(): void
    {
        $this->mailer(true)->send($this->email()->attach('report', 'report.txt', 'text/plain'));

        $copy = unserialize(serialize($this->bus->dispatched[0]));

        self::assertInstanceOf(SendEmailMessage::class, $copy);
        $message = $copy->getMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertSame('Hello', $message->getSubject());
        self::assertSame('report', $message->getAttachments()[0]->getBody());
    }
}

final class RecordingMailer implements MailerInterface
{
    /** @var list<array{RawMessage, ?Envelope}> */
    public array $sent = [];

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $this->sent[] = [$message, $envelope];
    }

    public function getSentMessage(): ?SentMessage
    {
        return null;
    }

    public function getTransport(): TransportInterface
    {
        return new NullTransport();
    }

    public function getRealTransport(): TransportInterface
    {
        return new NullTransport();
    }
}

final class RecordingBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $dispatched = [];

    public function dispatch(object $message, array $stamps = []): BusEnvelope
    {
        $this->dispatched[] = $message;

        return new BusEnvelope($message, $stamps);
    }
}
