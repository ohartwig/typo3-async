<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Presentation\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Prints the image processing instructions this site really requests, most
 * frequent first, as a ready imageProfiles list.
 *
 * Read from sys_file_processedfile, where TYPO3 keeps each processed file's
 * instructions in the order the template built them -- the order the checksum
 * depends on. A configuration with a crop area becomes a reference profile
 * with "crop": "default"; which variant the template really renders is the
 * one thing the table does not say, so check those against the template.
 */
#[AsCommand(name: 'koh-async:image-profiles:suggest', description: 'Suggest imageProfiles from the processed files this site has produced.')]
final class SuggestImageProfilesCommand extends Command
{
    public function __construct(private readonly ConnectionPool $connectionPool)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many profiles to print', '20')
            ->addOption('min-count', null, InputOption::VALUE_REQUIRED, 'Ignore configurations used for fewer files', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = $this->connectionPool->getConnectionForTable('sys_file_processedfile')
            ->select(['configuration'], 'sys_file_processedfile', ['task_type' => 'Image.CropScaleMask'])
            ->fetchFirstColumn();

        $profiles = self::rank($rows, (int) $input->getOption('min-count'), (int) $input->getOption('limit'));
        foreach ($profiles as ['profile' => $profile, 'count' => $count]) {
            $output->writeln(\sprintf('%6d  %s', $count, json_encode($profile, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)), OutputInterface::VERBOSITY_VERBOSE);
        }
        $output->writeln(json_encode(array_column($profiles, 'profile'), \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }

    /**
     * @param list<mixed> $serialized
     *
     * @return list<array{profile: array<string, mixed>, count: int}>
     */
    public static function rank(array $serialized, int $minCount, int $limit): array
    {
        $counts = [];
        $profiles = [];
        foreach ($serialized as $raw) {
            $profile = self::profile(\is_string($raw) ? $raw : '');
            if (null === $profile) {
                continue;
            }
            $key = (string) json_encode($profile);
            $profiles[$key] = $profile;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        arsort($counts);
        $ranked = [];
        foreach ($counts as $key => $count) {
            if ($count < $minCount || \count($ranked) >= $limit) {
                break;
            }
            $ranked[] = ['profile' => $profiles[$key], 'count' => $count];
        }

        return $ranked;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function profile(string $serialized): ?array
    {
        $configuration = @unserialize($serialized, ['allowed_classes' => false]);
        if (!\is_array($configuration) || [] === $configuration) {
            return null;
        }
        $profile = [];
        foreach ($configuration as $key => $value) {
            if (!\is_string($key)) {
                return null;
            }
            if ('crop' === $key && null !== $value) {
                // The area is the reference's, not the profile's: the handler
                // rebuilds it from the named variant, in this same position.
                $profile[$key] = 'default';
                continue;
            }
            if (\is_object($value) || \is_array($value)) {
                return null;
            }
            $profile[$key] = $value;
        }

        return $profile;
    }
}
