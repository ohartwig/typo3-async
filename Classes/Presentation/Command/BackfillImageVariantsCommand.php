<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Presentation\Command;

use Koh\Typo3Async\Infrastructure\Image\ImageVariantsBackfill;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Starts a backfill pass by hand: the consumer then queues the variants of
 * every image in use, a batch at a time. The consumer starts one by itself
 * when the profiles change (BackfillOnProfileChange); this is for the cases
 * it cannot see -- a GFX setting such as the AVIF quality, a storage moved,
 * processed files removed.
 *
 * Without --force it does what the consumer does: nothing when the last
 * pass was for the same profiles.
 */
#[AsCommand(name: 'koh-async:image-variants:backfill', description: 'Queue the image variants of every image in use for the current profiles.')]
final class BackfillImageVariantsCommand extends Command
{
    public function __construct(private readonly ImageVariantsBackfill $backfill)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Start a pass even if the last one was for the same profiles');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = time();
        if ($this->backfill->profiles($now)->isEmpty()) {
            $output->writeln('No image profiles: nothing to backfill (imageProfiles is empty and nothing was learned).');

            return Command::SUCCESS;
        }
        $pass = true === $input->getOption('force') ? $this->backfill->start($now) : $this->backfill->startIfChanged($now);
        $output->writeln(null === $pass
            ? 'The last pass was for the same profiles; use --force to run one again.'
            : \sprintf('Backfill pass %s queued; the consumer works it off a batch at a time.', $pass));

        return Command::SUCCESS;
    }
}
