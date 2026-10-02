<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Tests\Functional;

use Koh\Typo3Async\Domain\AsyncMode;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
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

    private function park(string $subject): void
    {
        $mail = new Email()->from('shop@example.org')->to('customer@example.org')->subject($subject)->text('.');
        $this->get(MessageBusInterface::class)->dispatch(
            new SendEmailMessage($mail),
            [new TransportNamesStamp(['koh_async_failed']), new SentToFailureTransportStamp('koh_async_mail')],
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

    #[Test]
    public function retryWantsIdsOrAll(): void
    {
        $tester = new CommandTester($this->get(CommandRegistry::class)->get('koh-async:failed:retry'));

        self::assertSame(2, $tester->execute([]));
    }
}
