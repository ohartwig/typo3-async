<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Tests\Functional;

use Koh\Typo3Async\Domain\AsyncMode;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Mime\Email;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * What MessagesParked asks a person to do must work: see a parked message,
 * return it to its queue, or drop it -- with commands TYPO3 itself does not
 * register.
 */
final class ParkedMessagesTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['koh/typo3-async'];

    protected bool $initializeDatabase = true;

    protected function setUp(): void
    {
        putenv(AsyncMode::ENVIRONMENT_VARIABLE . '=1');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv(AsyncMode::ENVIRONMENT_VARIABLE);
        parent::tearDown();
    }

    private function park(string $subject, string $when = 'now'): void
    {
        $mail = new Email()->from('shop@example.org')->to('customer@example.org')->subject($subject)->text('.');
        $this->get(MessageBusInterface::class)->dispatch(
            new SendEmailMessage($mail),
            [
                new TransportNamesStamp(['koh_async_failed']),
                new SentToFailureTransportStamp('koh_async_mail'),
                // What the failure listener writes when it parks a message.
                new RedeliveryStamp(0, new \DateTimeImmutable($when)),
            ],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function queued(string $queue): array
    {
        return $this->get(ConnectionPool::class)->getConnectionForTable('sys_messenger_messages')
            ->select(['*'], 'sys_messenger_messages', ['queue_name' => $queue])->fetchAllAssociative();
    }

    /**
     * @param array<string, mixed> $input
     */
    private function command(string $command, array $input): string
    {
        $tester = new CommandTester($this->get(CommandRegistry::class)->get($command));
        $tester->execute($input, ['interactive' => false]);

        return $tester->getDisplay();
    }

    #[Test]
    public function aParkedMessageIsShownAndReturnedToItsQueue(): void
    {
        $this->park('Order received');
        self::assertCount(1, $this->queued('failed'));

        self::assertStringContainsString(SendEmailMessage::class, $this->command('messenger:failed:show', []));

        $output = $this->command('koh-async:failed:retry', ['--all' => true]);

        self::assertStringContainsString('1 message(s) returned', $output);
        self::assertSame([], $this->queued('failed'));
        self::assertCount(1, $this->queued('mail'), 'back where the consumer reads it');
    }

    #[Test]
    public function aParkedMessageCanBeRemoved(): void
    {
        $this->park('Spam');
        $id = (string) $this->queued('failed')[0]['id'];

        $this->command('messenger:failed:remove', ['id' => [$id], '--force' => true]);

        self::assertSame([], $this->queued('failed'));
    }

    /**
     * Returning every parked message on a timer, or running the learning query
     * on one, is not scheduler work: both commands stay off the scheduler's
     * list, like messenger:failed:show and messenger:failed:remove.
     */
    #[Test]
    public function theOwnCommandsAreRegisteredButNotSchedulable(): void
    {
        $registry = $this->get(CommandRegistry::class);
        $schedulable = array_keys($registry->getSchedulableCommandsConfiguration());

        foreach (['koh-async:failed:retry', 'koh-async:image-profiles:suggest'] as $command) {
            self::assertTrue($registry->has($command), $command);
            self::assertNotContains($command, $schedulable, $command);
        }
    }

    #[Test]
    public function parkedMessagesOlderThanTheRetentionArePruned(): void
    {
        $this->park('Password reset', '-15 days');
        $this->park('Order received', '-1 day');
        self::assertCount(2, $this->queued('failed'));

        self::assertStringContainsString('1 parked message(s) older than 14 day(s) would be removed, 1 kept.', $this->command('koh-async:failed:prune', ['--dry-run' => true]));
        self::assertCount(2, $this->queued('failed'), 'a dry run removes nothing');

        self::assertStringContainsString('1 parked message(s) older than 14 day(s) removed, 1 kept.', $this->command('koh-async:failed:prune', []));
        $left = $this->queued('failed');
        self::assertCount(1, $left);
        self::assertStringContainsString('Order received', (string) $left[0]['body']);
    }

    /**
     * A row nobody here wrote: an envelope around an object of a class no
     * message of this extension contains. The consumer refuses it before any
     * of its objects exists, the transport drops the row, and the worker
     * stops with the exception -- it does not instantiate what it was told.
     */
    #[Test]
    public function aQueueRowMadeOfForeignClassesIsRefusedAndDropped(): void
    {
        $envelope = new Envelope(new \ArrayObject(['not a message']));
        $encoded = new PhpSerializer()->encode($envelope);
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_messenger_messages')->insert('sys_messenger_messages', [
            'body' => $encoded['body'],
            'headers' => json_encode($encoded['headers'] ?? [], \JSON_THROW_ON_ERROR),
            'queue_name' => 'mail',
            'created_at' => date('Y-m-d H:i:s'),
            'available_at' => date('Y-m-d H:i:s'),
        ]);
        self::assertCount(1, $this->queued('mail'));

        try {
            $this->command('messenger:consume', ['receivers' => ['koh_async_mail'], '--limit' => 1, '--time-limit' => 5]);
            self::fail('the consumer must not handle a row made of ArrayObject');
        } catch (MessageDecodingFailedException $e) {
            self::assertStringContainsString('ArrayObject', $e->getMessage());
        }

        self::assertSame([], $this->queued('mail'), 'the row is gone, not retried for ever');
        self::assertSame([], $this->queued('failed'), 'and not parked either: there is no message to park');
    }

    #[Test]
    public function retryWantsIdsOrAll(): void
    {
        $tester = new CommandTester($this->get(CommandRegistry::class)->get('koh-async:failed:retry'));

        self::assertSame(2, $tester->execute([]));
    }
}
