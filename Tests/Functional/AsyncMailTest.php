<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Tests\Functional;

use Koh\Typo3Async\Domain\AsyncMode;
use Koh\Typo3Async\Infrastructure\Mail\AsyncMailer;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The wiring end to end, in a booted TYPO3 with the extension and
 * MESSENGER_ASYNC=1: the container hands out the decorator, a mail lands in
 * the `mail` queue instead of the mail server, and a real messenger:consume
 * delivers it through TYPO3's own transport (an mbox file here).
 *
 * None of this is visible to a unit test, which builds every object by hand:
 * whether `decorates` reaches TYPO3's MailerInterface, whether TYPO3 finds the
 * handler, whether the routing in ext_localconf.php names a transport that
 * exists -- only a container build and a consumer run answer that.
 */
final class AsyncMailTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['koh/typo3-async'];

    protected bool $initializeDatabase = true;

    private string $mbox = '';

    protected function setUp(): void
    {
        // Before the instance boots: ext_localconf.php reads it.
        putenv(AsyncMode::ENVIRONMENT_VARIABLE . '=1');
        $this->configurationToUseInTestInstance = [
            'MAIL' => [
                'transport' => 'mbox',
                'transport_mbox_file' => sys_get_temp_dir() . '/koh-async-' . getmypid() . '.mbox',
                'defaultMailFromAddress' => 'site@example.org',
            ],
        ];
        parent::setUp();
        $this->mbox = sys_get_temp_dir() . '/koh-async-' . getmypid() . '.mbox';
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

    #[Test]
    public function theContainerHandsOutTheDecorator(): void
    {
        self::assertInstanceOf(AsyncMailer::class, $this->get(MailerInterface::class));
    }

    #[Test]
    public function aMailIsQueuedAndTheConsumerDeliversIt(): void
    {
        $this->get(MailerInterface::class)->send(
            (new Email())->from('shop@example.org')->to('customer@example.org')->subject('Order received')->text('Thank you.')
        );

        self::assertCount(1, $this->queued('mail'), 'queued in `mail`');
        self::assertFileDoesNotExist($this->mbox, 'not sent in the request');

        $consume = new CommandTester($this->get(CommandRegistry::class)->get('messenger:consume'));
        $consume->execute(['receivers' => ['koh_async_mail'], '--limit' => 1, '--time-limit' => 10]);

        self::assertSame([], $this->queued('mail'), 'taken off the queue');
        self::assertFileExists($this->mbox);
        self::assertStringContainsString('Subject: Order received', (string) file_get_contents($this->mbox));
    }
}
