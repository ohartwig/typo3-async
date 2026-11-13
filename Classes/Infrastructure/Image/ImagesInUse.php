<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Infrastructure\Image;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\FileType;

/**
 * The images a backfill prepares: those a page can render, read in uid
 * order and in batches.
 *
 * "In use" is a live, undeleted file reference to an image that is not
 * missing. An image nothing references is not rendered by any template, so
 * producing its variants would only cost ImageMagick time and storage. The
 * pixel limit of the upload path applies here too (UploadPolicy): a file the
 * upload would not have queued is not queued by the backfill either.
 */
final class ImagesInUse
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly UploadPolicy $policy,
    ) {}

    /**
     * Files after `$afterUid`, at most `$limit` looked at, uids ascending.
     */
    public function filesAfter(int $afterUid, int $limit): ImageBatch
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('f.uid')
            ->addSelectLiteral('MAX(m.width) AS width', 'MAX(m.height) AS height')
            ->from('sys_file', 'f')
            ->join('f', 'sys_file_reference', 'r', 'r.uid_local = f.uid')
            ->leftJoin('f', 'sys_file_metadata', 'm', 'm.file = f.uid')
            ->where(
                $queryBuilder->expr()->gt('f.uid', $queryBuilder->createNamedParameter($afterUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('f.type', $queryBuilder->createNamedParameter(FileType::IMAGE->value, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('f.missing', 0),
                $queryBuilder->expr()->eq('r.deleted', 0),
                $queryBuilder->expr()->eq('r.t3ver_wsid', 0),
            )
            ->groupBy('f.uid')
            ->orderBy('f.uid')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return $this->admitted($rows);
    }

    /**
     * Cropped references after `$afterUid`, at most `$limit`, as uids
     * ascending. Only those with a crop: an uncropped reference renders the
     * file's own variants, which the files phase prepares.
     */
    public function croppedReferencesAfter(int $afterUid, int $limit): ImageBatch
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('r.uid')
            ->addSelectLiteral('MAX(m.width) AS width', 'MAX(m.height) AS height')
            ->from('sys_file_reference', 'r')
            ->join('r', 'sys_file', 'f', 'f.uid = r.uid_local')
            ->leftJoin('f', 'sys_file_metadata', 'm', 'm.file = f.uid')
            ->where(
                $queryBuilder->expr()->gt('r.uid', $queryBuilder->createNamedParameter($afterUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('r.deleted', 0),
                $queryBuilder->expr()->eq('r.t3ver_wsid', 0),
                $queryBuilder->expr()->isNotNull('r.crop'),
                $queryBuilder->expr()->neq('r.crop', $queryBuilder->createNamedParameter('')),
                $queryBuilder->expr()->eq('f.type', $queryBuilder->createNamedParameter(FileType::IMAGE->value, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('f.missing', 0),
            )
            ->groupBy('r.uid')
            ->orderBy('r.uid')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return $this->admitted($rows);
    }

    /**
     * The uids, without those over the pixel limit. The cursor still moves
     * past those: the batch's last uid is the last row read, admitted or not.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function admitted(array $rows): ImageBatch
    {
        $uids = [];
        foreach ($rows as $row) {
            if ($this->policy->allowsDimensions((int) ($row['width'] ?? 0), (int) ($row['height'] ?? 0))) {
                $uids[] = (int) $row['uid'];
            }
        }

        return new ImageBatch($uids, [] === $rows ? null : (int) $rows[array_key_last($rows)]['uid']);
    }
}
