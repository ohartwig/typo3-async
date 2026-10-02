<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Tests\Unit\Infrastructure\Mail;

use Koh\Typo3Async\Infrastructure\Mail\SendEmailHandler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use TYPO3\CMS\Core\Mail\MailerInterface;

final class SendEmailHandlerTest extends TestCase
{
    #[Test]
    public function itSendsTheQueuedMailThroughTheMailerItWasGiven(): void
    {
        $email = new Email()->to('customer@example.org')->text('x');
        $envelope = new Envelope(new Address('bounce@example.org'), [new Address('customer@example.org')]);
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->with($email, $envelope);

        (new SendEmailHandler($mailer))(new SendEmailMessage($email, $envelope));
    }

    #[Test]
    public function aSendingFailureReachesTheWorker(): void
    {
        // The worker decides about retry and failure queue; the handler must
        // not swallow the error, or the mail would be acknowledged and lost.
        $mailer = self::createStub(MailerInterface::class);
        $mailer->method('send')->willThrowException(new \RuntimeException('SMTP down'));

        try {
            (new SendEmailHandler($mailer))(new SendEmailMessage(new Email()->to('a@example.org')->text('x')));
            self::fail('the failure was swallowed');
        } catch (\RuntimeException $e) {
            self::assertSame('SMTP down', $e->getMessage());
        }
    }
}
