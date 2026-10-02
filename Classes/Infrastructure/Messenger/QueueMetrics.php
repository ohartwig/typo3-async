<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Infrastructure\Messenger;

/**
 * The queue figures in the Prometheus text format.
 *
 * Every known queue is always written, with zeros when empty: a series that
 * is missing cannot be compared, and an alert on it would never fire.
 *
 *   koh_async_queue_messages{queue}            messages waiting, due or not
 *   koh_async_queue_oldest_age_seconds{queue}  how long the oldest DUE message
 *                                              has waited; 0 when none is due.
 *                                              A retry waiting for its delay
 *                                              is not overdue and not counted.
 *   koh_async_metrics_timestamp_seconds        when this was written: a value
 *                                              that stops moving means the
 *                                              consumer loop stopped turning
 */
final class QueueMetrics
{
    public const QUEUES = ['mail', 'images', 'failed'];

    /**
     * @param array<string, array{messages: int, oldestDueAt: int|null}> $queues by queue name
     */
    public static function render(array $queues, int $now): string
    {
        foreach (self::QUEUES as $queue) {
            $queues[$queue] ??= ['messages' => 0, 'oldestDueAt' => null];
        }
        ksort($queues);
        $lines = [
            '# HELP koh_async_queue_messages Messages waiting in the queue, due or delayed.',
            '# TYPE koh_async_queue_messages gauge',
        ];
        foreach ($queues as $queue => $figures) {
            $lines[] = \sprintf('koh_async_queue_messages{queue="%s"} %d', self::label($queue), $figures['messages']);
        }
        $lines[] = '# HELP koh_async_queue_oldest_age_seconds Seconds the oldest due message has waited; 0 when none is due.';
        $lines[] = '# TYPE koh_async_queue_oldest_age_seconds gauge';
        foreach ($queues as $queue => $figures) {
            $age = null === $figures['oldestDueAt'] ? 0 : max(0, $now - $figures['oldestDueAt']);
            $lines[] = \sprintf('koh_async_queue_oldest_age_seconds{queue="%s"} %d', self::label($queue), $age);
        }
        $lines[] = '# HELP koh_async_metrics_timestamp_seconds When the consumer last wrote these figures.';
        $lines[] = '# TYPE koh_async_metrics_timestamp_seconds gauge';
        $lines[] = \sprintf('koh_async_metrics_timestamp_seconds %d', $now);

        return implode("\n", $lines) . "\n";
    }

    private static function label(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }
}
