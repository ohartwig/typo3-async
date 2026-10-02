<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Tests\Unit\Infrastructure\Messenger;

use Koh\Typo3Async\Infrastructure\Messenger\QueueMetrics;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class QueueMetricsTest extends TestCase
{
    #[Test]
    public function everyKnownQueueIsWrittenEvenWhenEmpty(): void
    {
        $text = QueueMetrics::render([], 1000);

        foreach (['mail', 'images', 'failed'] as $queue) {
            self::assertStringContainsString("koh_async_queue_messages{queue=\"$queue\"} 0\n", $text);
            self::assertStringContainsString("koh_async_queue_oldest_age_seconds{queue=\"$queue\"} 0\n", $text);
        }
        self::assertStringContainsString("koh_async_metrics_timestamp_seconds 1000\n", $text);
    }

    #[Test]
    public function theAgeIsThatOfTheOldestDueMessage(): void
    {
        $text = QueueMetrics::render([
            'mail' => ['messages' => 3, 'oldestDueAt' => 400],
            'images' => ['messages' => 2, 'oldestDueAt' => null],
            'other' => ['messages' => 1, 'oldestDueAt' => 990],
        ], 1000);

        self::assertStringContainsString("koh_async_queue_messages{queue=\"mail\"} 3\n", $text);
        self::assertStringContainsString("koh_async_queue_oldest_age_seconds{queue=\"mail\"} 600\n", $text);
        self::assertStringContainsString("koh_async_queue_messages{queue=\"images\"} 2\n", $text, 'delayed retries are waiting');
        self::assertStringContainsString("koh_async_queue_oldest_age_seconds{queue=\"images\"} 0\n", $text, 'but none is overdue');
        self::assertStringContainsString("koh_async_queue_oldest_age_seconds{queue=\"other\"} 10\n", $text, 'unknown queues are written too');
    }
}
