<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Presentation\Command;

use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * Puts parked messages back into the queue they came from, as new messages
 * with a fresh retry count. The consumer then handles them with the usual
 * retries and failure path.
 *
 * Not Symfony's messenger:failed:retry: that one handles the message in this
 * process with Symfony's own event dispatcher, where TYPO3's retry and
 * failure listeners do not run -- a message failing again would be lost.
 * Inspect with messenger:failed:show, drop with messenger:failed:remove.
 */
#[AsCommand(name: 'koh-async:failed:retry', description: 'Return parked messages to the queue they came from.')]
final class RetryParkedMessagesCommand extends Command
{
    public function __construct(
        private readonly ListableReceiverInterface $failed,
        private readonly ContainerInterface $senders,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::IS_ARRAY, 'Ids as messenger:failed:show lists them')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Every parked message');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<string> $ids */
        $ids = $input->getArgument('id');
        if ([] === $ids && true !== $input->getOption('all')) {
            $output->writeln('<error>Name ids or pass --all.</error>');

            return Command::INVALID;
        }
        $envelopes = [] === $ids ? $this->failed->all() : array_filter(array_map($this->failed->find(...), $ids));
        $returned = 0;
        foreach ($envelopes as $envelope) {
            $returned += $this->returnToQueue($envelope, $output) ? 1 : 0;
        }
        $output->writeln(\sprintf('%d message(s) returned to their queue.', $returned));

        return Command::SUCCESS;
    }

    private function returnToQueue(Envelope $envelope, OutputInterface $output): bool
    {
        $origin = $envelope->last(SentToFailureTransportStamp::class)?->getOriginalReceiverName();
        if (null === $origin || !$this->senders->has($origin)) {
            $output->writeln(\sprintf('<comment>Skipped %s: no known queue to return it to.</comment>', $envelope->getMessage()::class));

            return false;
        }
        $sender = $this->senders->get($origin);
        if (!$sender instanceof SenderInterface) {
            return false;
        }
        // A new envelope: the retry count and the failure stamps start over.
        $sender->send(Envelope::wrap($envelope->getMessage()));
        $this->failed->reject($envelope);

        return true;
    }
}
