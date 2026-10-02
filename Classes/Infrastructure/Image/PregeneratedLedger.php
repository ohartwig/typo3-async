<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Infrastructure\Image;

use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Remembers which processed files the consumer produced, so the learner can
 * leave them out (see ext_tables.sql).
 */
final class PregeneratedLedger
{
    public const TABLE = 'tx_kohasync_pregenerated';

    public function __construct(private readonly ConnectionPool $connectionPool) {}

    public function record(int $processedFileUid): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $known = $connection->count('*', self::TABLE, ['processedfile' => $processedFileUid]);
        if (0 === $known) {
            $connection->insert(self::TABLE, ['processedfile' => $processedFileUid]);
        }
    }
}
