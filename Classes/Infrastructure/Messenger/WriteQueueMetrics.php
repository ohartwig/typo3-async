<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Infrastructure\Messenger;

use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The consumer writes the queue figures (QueueMetrics) to a file while it
 * runs; a sidecar in the same pod serves that file to Prometheus.
 *
 * Written from the worker loop on purpose: the loop turns after every message
 * and about once a second while idle, so a timestamp that stops moving is
 * itself the signal that the consumer hangs -- something a probe of the
 * process would not see. At most every 15 seconds; the query is two
 * aggregates over sys_messenger_messages.
 *
 * Only where KOH_ASYNC_METRICS_FILE names a file. Everywhere else this does
 * nothing.
 */
final class WriteQueueMetrics
{
    public const ENVIRONMENT_VARIABLE = 'KOH_ASYNC_METRICS_FILE';

    private const INTERVAL_SECONDS = 15;

    private int $writtenAt = 0;

    public function __construct(private readonly ConnectionPool $connectionPool) {}

    #[AsEventListener(identifier: 'koh-async/write-queue-metrics')]
    public function __invoke(WorkerRunningEvent $event): void
    {
        $file = getenv(self::ENVIRONMENT_VARIABLE);
        $now = time();
        if (!\is_string($file) || '' === $file || $now - $this->writtenAt < self::INTERVAL_SECONDS) {
            return;
        }
        $this->write($file, $now);
    }

    public function write(string $file, int $now): void
    {
        $this->writtenAt = $now;
        $tmp = $file . '.tmp';
        if (false !== file_put_contents($tmp, QueueMetrics::render($this->queues($now), $now))) {
            rename($tmp, $file);
        }
    }

    /**
     * @return array<string, array{messages: int, oldestDueAt: int|null}>
     */
    private function queues(int $now): array
    {
        $connection = $this->connectionPool->getConnectionForTable('sys_messenger_messages');
        // The Doctrine transport stores UTC, second precision.
        $nowUtc = gmdate('Y-m-d H:i:s', $now);
        $queues = [];
        $rows = $connection->executeQuery(
            'SELECT queue_name, COUNT(*) AS messages,'
            . ' MIN(CASE WHEN available_at <= ? THEN available_at END) AS oldest_due'
            . ' FROM sys_messenger_messages WHERE delivered_at IS NULL GROUP BY queue_name',
            [$nowUtc],
        )->fetchAllAssociative();
        foreach ($rows as $row) {
            $oldest = \is_string($row['oldest_due'] ?? null) ? strtotime($row['oldest_due'] . ' UTC') : false;
            $queues[(string) $row['queue_name']] = [
                'messages' => (int) $row['messages'],
                'oldestDueAt' => false === $oldest ? null : $oldest,
            ];
        }

        return $queues;
    }
}
