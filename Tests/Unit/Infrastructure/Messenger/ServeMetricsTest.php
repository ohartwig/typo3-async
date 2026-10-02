<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Tests\Unit\Infrastructure\Messenger;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ServeMetricsTest extends TestCase
{
    private string $file = '';

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/koh-async-metrics-' . getmypid();
        putenv('KOH_ASYNC_METRICS_FILE=' . $this->file);
    }

    protected function tearDown(): void
    {
        putenv('KOH_ASYNC_METRICS_FILE');
        @unlink($this->file);
    }

    private function serve(string $uri): string
    {
        $_SERVER['REQUEST_URI'] = $uri;
        ob_start();
        include \dirname(__DIR__, 4) . '/Resources/Private/Php/serve-metrics.php';

        return (string) ob_get_clean();
    }

    #[Test]
    public function itServesTheFileTheConsumerWrote(): void
    {
        file_put_contents($this->file, "koh_async_metrics_timestamp_seconds 1\n");

        self::assertSame("koh_async_metrics_timestamp_seconds 1\n", $this->serve('/metrics'));
    }

    #[Test]
    public function withoutAFileItSaysSoInsteadOfReportingEmptyQueues(): void
    {
        self::assertSame("no queue figures yet\n", $this->serve('/metrics'));
        self::assertSame('', $this->serve('/etc/passwd'));
    }
}
