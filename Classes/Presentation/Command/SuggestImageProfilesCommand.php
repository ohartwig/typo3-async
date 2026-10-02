<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Presentation\Command;

use Koh\Typo3Async\Infrastructure\Image\ImageProfiles;
use Koh\Typo3Async\Infrastructure\Image\LearnedImageProfiles;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints the profiles the learning mode produces on this installation: each
 * with the number and share of originals it was requested for, and the crop
 * variant's real name where a crop was involved. The last line is the same
 * list as JSON, usable as imageProfiles where learning is switched off.
 */
#[AsCommand(name: 'koh-async:image-profiles:suggest', description: 'Show the image profiles learned from what this site has rendered.')]
final class SuggestImageProfilesCommand extends Command
{
    public function __construct(
        private readonly LearnedImageProfiles $learned,
        private readonly ImageProfiles $configured,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'share',
            null,
            InputOption::VALUE_REQUIRED,
            'Minimum percent of originals; default: learnImageProfiles, or 10 where learning is off',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $option = $input->getOption('share');
        $share = is_numeric($option)
            ? max(0.0, min(100.0, (float) $option)) / 100
            : ($this->configured->learns() ? $this->configured->learnShare() : ImageProfiles::DEFAULT_LEARN_PERCENT / 100);
        $learned = $this->learned->learn($share, time());
        foreach ($learned as ['profile' => $profile, 'originals' => $originals, 'share' => $of]) {
            $output->writeln(
                \sprintf('%6d %5.1f%%  %s', $originals, 100 * $of, json_encode($profile, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)),
                OutputInterface::VERBOSITY_VERBOSE,
            );
        }
        $output->writeln(json_encode(array_column($learned, 'profile'), \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
