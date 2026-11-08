<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Tests\Functional;

use Koh\Typo3Async\Domain\AsyncMode;
use Koh\Typo3Async\Infrastructure\Messenger\Transports;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Mime\Email;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * koh_async_cache is a transport for other extensions: nothing here routes
 * to it. What they rely on is that the name exists in the container, that
 * messenger:consume reads it, and that a message there has the same retry and
 * failure path as on the other queues. A mail stands in for their message;
 * which class it is does not matter to the transport.
 */
final class CacheTransportTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['koh/typo3-async'];

    protected bool $initializeDatabase = true;

    private string $mbox = '';

    protected function setUp(): void
    {
        putenv(AsyncMode::ENVIRONMENT_VARIABLE . '=1');
        $this->mbox = sys_get_temp_dir() . '/koh-async-cache-' . getmypid() . '.mbox';
        $this->configurationToUseInTestInstance = [
            'MAIL' => [
                'transport' => 'mbox',
                'transport_mbox_file' => $this->mbox,
                'defaultMailFromAddress' => 'site@example.org',
            ],
        ];
        parent::setUp();
        @unlink($this->mbox);
    }

    protected function tearDown(): void
    {
        putenv(AsyncMode::ENVIRONMENT_VARIABLE);
        @unlink($this->mbox);
        parent::tearDown();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function queued(string $queue): array
    {
        return $this->get(ConnectionPool::class)->getConnectionForTable('sys_messenger_messages')
            ->select(['*'], 'sys_messenger_messages', ['queue_name' => $queue])->fetchAllAssociative();
    }

    private function message(string $subject): SendEmailMessage
    {
        return new SendEmailMessage((new Email())->from('shop@example.org')->to('customer@example.org')->subject($subject)->text('.'));
    }

    #[Test]
    public function aMessageSentToTheCacheTransportIsQueuedAndConsumed(): void
    {
        $this->get(MessageBusInterface::class)->dispatch($this->message('Via cache'), [new TransportNamesStamp([Transports::CACHE])]);

        self::assertCount(1, $this->queued('cache'), 'queued in `cache`');
        self::assertFileDoesNotExist($this->mbox, 'not handled in the request');

        $consume = new CommandTester($this->get(CommandRegistry::class)->get('messenger:consume'));
        $consume->execute(['receivers' => [Transports::CACHE], '--limit' => 1, '--time-limit' => 10]);

        self::assertSame([], $this->queued('cache'), 'taken off the queue');
        self::assertStringContainsString('Subject: Via cache', (string) file_get_contents($this->mbox));
    }

    #[Test]
    public function aMessageParkedFromTheCacheTransportReturnsToIt(): void
    {
        $this->get(MessageBusInterface::class)->dispatch($this->message('Parked'), [
            new TransportNamesStamp([Transports::FAILED]),
            new SentToFailureTransportStamp(Transports::CACHE),
            new RedeliveryStamp(0),
        ]);
        self::assertCount(1, $this->queued('failed'));

        $tester = new CommandTester($this->get(CommandRegistry::class)->get('koh-async:failed:retry'));
        $tester->execute(['--all' => true], ['interactive' => false]);

        self::assertSame([], $this->queued('failed'));
        self::assertCount(1, $this->queued('cache'), 'back where the consumer reads it');
    }

    #[Test]
    public function theTransportNamesAreTheOnesTheContainerKnows(): void
    {
        $consume = new CommandTester($this->get(CommandRegistry::class)->get('messenger:consume'));

        self::assertSame(0, $consume->execute([
            'receivers' => [Transports::MAIL, Transports::IMAGES, Transports::CACHE],
            '--time-limit' => 1,
        ]));
    }
}
