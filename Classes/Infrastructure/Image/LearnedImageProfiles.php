<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Infrastructure\Image;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Imaging\ImageManipulation\Area;
use TYPO3\CMS\Core\Imaging\ImageManipulation\CropVariantCollection;
use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * The learned profiles of this installation, read from sys_file_processedfile
 * and sys_file_reference -- leaving out what the consumer produced itself
 * (PregeneratedLedger), which is no evidence of what a template requests.
 *
 * Only the consumer asks, never a request: the read scans the processed-file
 * table. The result is kept for an hour per process -- the consumer restarts
 * hourly anyway (--time-limit=3600), so a template change is followed within
 * about an hour without a deploy.
 */
final class LearnedImageProfiles
{
    public const MIN_ORIGINALS = 3;

    private const TTL_SECONDS = 3600;

    /** @var array<string, array{at: int, learned: list<array{profile: array<string, mixed>, originals: int, share: float}>}> */
    private array $memo = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ResourceFactory $resourceFactory,
        private readonly ProfileLearner $learner,
    ) {}

    /**
     * @return list<array{profile: array<string, mixed>, originals: int, share: float}>
     */
    public function learn(float $minShare, int $now): array
    {
        $key = 'share:' . $minShare;
        if (isset($this->memo[$key]) && $now - $this->memo[$key]['at'] < self::TTL_SECONDS) {
            return $this->memo[$key]['learned'];
        }
        $query = $this->connectionPool->getQueryBuilderForTable('sys_file_processedfile');
        $query->getRestrictions()->removeAll();
        $rows = $query
            ->select('p.original', 'p.configuration')
            ->from('sys_file_processedfile', 'p')
            ->leftJoin('p', PregeneratedLedger::TABLE, 'g', $query->expr()->eq('g.processedfile', $query->quoteIdentifier('p.uid')))
            ->where(
                $query->expr()->eq('p.task_type', $query->createNamedParameter('Image.CropScaleMask')),
                $query->expr()->isNull('g.processedfile'),
            )
            ->executeQuery()
            ->iterateAssociative();
        /** @var iterable<array{original: int|string, configuration: mixed}> $rows */
        $learned = $this->learner->learn($rows, $this->variantsOf(...), $minShare, self::MIN_ORIGINALS);
        $this->memo[$key] = ['at' => $now, 'learned' => $learned];

        return $learned;
    }

    /**
     * @return list<string>
     */
    private function variantsOf(int $original, Area $area): array
    {
        try {
            $file = $this->resourceFactory->getFileObject($original);
        } catch (ResourceDoesNotExistException) {
            return [];
        }
        $names = [];
        foreach ($this->cropsOf($original) as $crop) {
            $ids = json_decode($crop, true);
            if (!\is_array($ids)) {
                continue;
            }
            $collection = CropVariantCollection::create($crop);
            foreach (array_keys($ids) as $id) {
                $candidate = $collection->getCropArea((string) $id);
                if (!$candidate->isEmpty() && self::same($candidate->makeAbsoluteBasedOnFile($file), $area)) {
                    $names[(string) $id] = true;
                }
            }
        }

        return array_keys($names);
    }

    /**
     * @return list<string>
     */
    private function cropsOf(int $original): array
    {
        /** @var list<string> $crops */
        $crops = $this->connectionPool->getConnectionForTable('sys_file_reference')
            ->select(['crop'], 'sys_file_reference', ['uid_local' => $original, 'deleted' => 0])
            ->fetchFirstColumn();

        return array_values(array_filter($crops, static fn(mixed $crop): bool => \is_string($crop) && '' !== $crop));
    }

    private static function same(Area $a, Area $b): bool
    {
        return abs($a->getOffsetLeft() - $b->getOffsetLeft()) < 0.01
            && abs($a->getOffsetTop() - $b->getOffsetTop()) < 0.01
            && abs($a->getWidth() - $b->getWidth()) < 0.01
            && abs($a->getHeight() - $b->getHeight()) < 0.01;
    }
}
