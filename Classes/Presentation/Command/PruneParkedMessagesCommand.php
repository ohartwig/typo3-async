<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Presentation\Command;

use Koh\Typo3Async\Infrastructure\Messenger\FailedRetention;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;

/**
 * Removes parked messages older than the retention. Meant for the scheduler:
 * a parked mail carries its recipients, body and attachments, and nobody
 * should have to remember to drop it by hand.
 *
 * A message's age is the moment it was parked, which Symfony's failure
 * listener writes as a RedeliveryStamp. A parked message without that stamp
 * (put there by other means) has no known age and is kept.
 */
#[AsCommand(name: 'koh-async:failed:prune', description: 'Remove parked messages older than the retention.')]
final class PruneParkedMessagesCommand extends Command
{
    public function __construct(
        private readonly ListableReceiverInterface $failed,
        private readonly FailedRetention $retention,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Keep messages parked within this many days; default: failedRetentionDays (' . FailedRetention::DEFAULT_DAYS . '), 0 keeps everything')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count, remove nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = $input->getOption('days');
        $retention = null === $days ? $this->retention : FailedRetention::fromConfiguration($days);
        $cutoff = $retention->cutoff(new \DateTimeImmutable());
        if (null === $cutoff) {
            $output->writeln('Retention is 0: parked messages are kept, nothing removed.');

            return Command::SUCCESS;
        }
        $dryRun = true === $input->getOption('dry-run');
        $removed = 0;
        $kept = 0;
        foreach ($this->failed->all() as $envelope) {
            if (!$this->isDue($envelope, $cutoff)) {
                ++$kept;

                continue;
            }
            if (!$dryRun) {
                $this->failed->reject($envelope);
            }
            ++$removed;
        }
        $output->writeln(\sprintf(
            '%d parked message(s) older than %d day(s) %s, %d kept.',
            $removed,
            $retention->days(),
            $dryRun ? 'would be removed' : 'removed',
            $kept,
        ));

        return Command::SUCCESS;
    }

    private function isDue(Envelope $envelope, \DateTimeImmutable $cutoff): bool
    {
        $parkedAt = $envelope->last(RedeliveryStamp::class)?->getRedeliveredAt();

        return null !== $parkedAt && $parkedAt < $cutoff;
    }
}
