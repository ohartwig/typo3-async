<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Tests\Unit\Presentation\Command;

use Koh\Typo3Async\Infrastructure\Messenger\FailedRetention;
use Koh\Typo3Async\Presentation\Command\PruneParkedMessagesCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;

final class PruneParkedMessagesCommandTest extends TestCase
{
    private static function parked(string $when): Envelope
    {
        return new Envelope(new \stdClass(), [new RedeliveryStamp(0, new \DateTimeImmutable($when))]);
    }

    /**
     * @param list<Envelope> $envelopes
     */
    private static function failed(array $envelopes): RecordingFailedTransport
    {
        return new RecordingFailedTransport($envelopes);
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function prune(ListableReceiverInterface $failed, FailedRetention $retention, array $input = []): string
    {
        $tester = new CommandTester(new PruneParkedMessagesCommand($failed, $retention));
        self::assertSame(0, $tester->execute($input));

        return $tester->getDisplay();
    }

    #[Test]
    public function messagesParkedLongerThanTheRetentionAreRemovedAndTheRestKept(): void
    {
        $old = self::parked('-15 days');
        $fresh = self::parked('-13 days');
        $ageless = new Envelope(new \stdClass());
        $failed = self::failed([$old, $fresh, $ageless]);

        $output = self::prune($failed, new FailedRetention(14));

        self::assertSame([$old], $failed->rejected, 'the fresh one and the one of unknown age stay');
        self::assertStringContainsString('1 parked message(s) older than 14 day(s) removed, 2 kept.', $output);
    }

    #[Test]
    public function aDryRunCountsWithoutRemoving(): void
    {
        $failed = self::failed([self::parked('-40 days')]);

        $output = self::prune($failed, new FailedRetention(14), ['--dry-run' => true]);

        self::assertSame([], $failed->rejected);
        self::assertStringContainsString('1 parked message(s) older than 14 day(s) would be removed, 0 kept.', $output);
    }

    #[Test]
    public function theDaysOptionOverridesTheSettingAndZeroKeepsEverything(): void
    {
        $failed = self::failed([self::parked('-5 days')]);
        self::prune($failed, new FailedRetention(14), ['--days' => '3']);
        self::assertCount(1, $failed->rejected, 'three days: the five-day-old message goes');

        $failed = self::failed([self::parked('-500 days')]);
        $output = self::prune($failed, new FailedRetention(0));
        self::assertSame([], $failed->rejected);
        self::assertStringContainsString('Retention is 0', $output);
    }

    #[Test]
    public function theSettingIsReadAsDaysWithADefaultAndNoNegatives(): void
    {
        self::assertSame(14, FailedRetention::fromConfiguration(null)->days());
        self::assertSame(14, FailedRetention::fromConfiguration('soon')->days());
        self::assertSame(3, FailedRetention::fromConfiguration('3')->days());
        self::assertSame(0, FailedRetention::fromConfiguration(-2)->days());
        self::assertNull((new FailedRetention(0))->cutoff(new \DateTimeImmutable()));
        self::assertEquals(new \DateTimeImmutable('2026-10-02T12:00:00Z'), (new FailedRetention(14))->cutoff(new \DateTimeImmutable('2026-10-16T12:00:00Z')));
    }
}

final class RecordingFailedTransport implements ListableReceiverInterface
{
    /** @var list<Envelope> */
    public array $rejected = [];

    /** @param list<Envelope> $envelopes */
    public function __construct(private readonly array $envelopes) {}

    public function all(?int $limit = null): iterable
    {
        return $this->envelopes;
    }

    public function find(mixed $id): ?Envelope
    {
        return null;
    }

    public function get(): iterable
    {
        return [];
    }

    public function ack(Envelope $envelope): void {}

    public function reject(Envelope $envelope): void
    {
        $this->rejected[] = $envelope;
    }
}
