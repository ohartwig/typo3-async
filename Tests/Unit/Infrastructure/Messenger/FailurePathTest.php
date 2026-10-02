<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Tests\Unit\Infrastructure\Messenger;

use Koh\Typo3Async\Infrastructure\Messenger\ParkFailedMessage;
use Koh\Typo3Async\Infrastructure\Messenger\RecordFailureReason;
use Koh\Typo3Async\Infrastructure\Messenger\RetryFailedMessage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;

/**
 * The path a mail takes when sending fails: back onto its queue, delayed, three
 * times, then into the failure queue -- never dropped. Wired the way
 * Configuration/Services.yaml wires it, with in-memory transports.
 */
final class FailurePathTest extends TestCase
{
    #[Test]
    public function aFailingMailIsRetriedThreeTimesAndThenParked(): void
    {
        $mail = new InMemoryTransport();
        $failed = new InMemoryTransport();
        $retry = new RetryFailedMessage(
            new ServiceLocator(['koh_async_mail' => static fn() => $mail]),
            new ServiceLocator(['koh_async_mail' => static fn() => new MultiplierRetryStrategy(3, 30000, 2)]),
            new NullLogger(),
        );
        $record = new RecordFailureReason();
        $park = new ParkFailedMessage(new ServiceLocator(['koh_async_mail' => static fn() => $failed]), new NullLogger());

        $envelope = new Envelope(new SendEmailMessage(new Email()->to('a@example.org')->text('x')), [new ReceivedStamp('koh_async_mail')]);
        $delays = [];
        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            $event = new WorkerMessageFailedEvent($envelope, 'koh_async_mail', new \RuntimeException('SMTP down'));
            $record($event);
            $retry($event);
            $park($event);
            if ($event->willRetry()) {
                $sent = $mail->getSent();
                $requeued = end($sent);
                self::assertInstanceOf(Envelope::class, $requeued);
                $delays[] = $requeued->last(DelayStamp::class)?->getDelay();
                $envelope = $requeued->withoutAll(ReceivedStamp::class)->with(new ReceivedStamp('koh_async_mail'));
            }
        }

        // 30 s, 60 s and 120 s apart, each with Symfony's default 10 % jitter so
        // that the retries of many mails do not hit the mail server at once.
        self::assertCount(3, $delays, 'three retries');
        foreach ([30000, 60000, 120000] as $i => $base) {
            self::assertGreaterThanOrEqual((int) ($base * 0.9), $delays[$i], "retry {$i}");
            self::assertLessThanOrEqual((int) ($base * 1.1), $delays[$i], "retry {$i}");
        }
        self::assertCount(3, $mail->getSent());
        self::assertCount(1, $failed->getSent(), 'the fourth failure parks it instead of dropping it');
        $parked = $failed->getSent()[0];
        self::assertSame('koh_async_mail', $parked->last(SentToFailureTransportStamp::class)?->getOriginalReceiverName(), 'it remembers which queue to go back to');
        self::assertSame('SMTP down', $parked->last(ErrorDetailsStamp::class)?->getExceptionMessage(), 'and why it is there');
    }
}
