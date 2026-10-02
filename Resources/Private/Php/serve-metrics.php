<?php

// Router for `php -S`: serves the file the consumer writes (WriteQueueMetrics)
// as Prometheus text. No TYPO3 here and no database: the sidecar that runs
// this only reads a file in a volume it shares with the consumer.
//
//   php -S 0.0.0.0:9106 serve-metrics.php     with KOH_ASYNC_METRICS_FILE set
//
// No file yet (consumer still starting) answers 503, so the scrape fails
// visibly instead of reporting an empty queue.

declare(strict_types=1);

$file = getenv('KOH_ASYNC_METRICS_FILE');
if ('/metrics' !== parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), \PHP_URL_PATH)) {
    http_response_code(404);

    return true;
}
$body = is_string($file) && '' !== $file ? @file_get_contents($file) : false;
if (false === $body) {
    http_response_code(503);
    echo "no queue figures yet\n";

    return true;
}
header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
echo $body;

return true;
