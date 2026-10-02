<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Infrastructure\Image;

use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * DataHandler hook: a file reference saved with a crop queues its cropped
 * variants. A crop lives on the reference, not on the file, so no FAL event
 * sees it; the editor sets it in the image manipulation wizard and saves the
 * content element.
 *
 * Only saves that carry the `crop` field count. New references resolve their
 * NEW id through the DataHandler first.
 */
final class QueueCroppedReferences
{
    public function __construct(private readonly QueueImageVariants $queue) {}

    /**
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_afterDatabaseOperations(
        string $status,
        string $table,
        int|string $id,
        array $fieldArray,
        DataHandler $dataHandler,
    ): void {
        if ('sys_file_reference' !== $table || !\array_key_exists('crop', $fieldArray)) {
            return;
        }
        if (!is_numeric($id)) {
            $id = $dataHandler->substNEWwithIDs[$id] ?? 0;
        }
        $this->queue->queueReference((int) $id);
    }
}
