<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Write;

use Hypervel\Container\Container;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The `log` driver: logs the failed batch's details and drops it.
 *
 * The payload is not logged, only where it was going and how big it was.
 * A failure of the logger itself goes to PHP's `error_log()`.
 *
 * @see src/docs/writing.md#when-a-batch-cannot-be-written
 */
class LogFailureStore implements FailureStore
{
    public const string DRIVER = 'log';

    /**
     * Log a batch that failed after its retries.
     */
    public function store(FailedBatch $batch): void
    {
        $message = sprintf(
            'InfluxDB connection [%s] dropped a batch for bucket [%s] after %d %s: %s',
            $batch->connection,
            $batch->bucket,
            $batch->attempts,
            $batch->attempts === 1 ? 'attempt' : 'attempts',
            $batch->exception,
        );

        try {
            Container::getInstance()->make(LoggerInterface::class)->warning($message, [
                'connection' => $batch->connection,
                'bucket' => $batch->bucket,
                'org' => $batch->org,
                'precision' => $batch->precision,
                'bytes' => strlen($batch->payload),
                'attempts' => $batch->attempts,
                'failedAt' => $batch->failedAt->format(DATE_ATOM),
            ]);
        } catch (Throwable) {
            error_log($message);
        }
    }
}
