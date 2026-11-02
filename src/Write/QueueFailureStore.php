<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Write;

use Ipsocode\InfluxDB\Jobs\ReplayFailedBatch;

/**
 * The `queue` driver: dispatches each failed batch as a ReplayFailedBatch job, so the queue's own tries and backoff replay it.
 *
 * It is not a ReplayableFailureStore: the batches live in the queue, and its workers write them.
 *
 * @see src/docs/writing.md#the-queue-driver
 */
class QueueFailureStore implements FailureStore
{
    public const string DRIVER = 'queue';

    /**
     * @param null|string $connection the queue connection's name; null is the application's default connection
     * @param null|string $queue the queue's name; null is the connection's default queue
     */
    public function __construct(
        private readonly ?string $connection = null,
        private readonly ?string $queue = null,
    ) {
    }

    /**
     * Dispatch a batch that failed after its retries onto the queue.
     */
    public function store(FailedBatch $batch): void
    {
        ReplayFailedBatch::dispatch($batch)
            ->onConnection($this->connection)
            ->onQueue($this->queue);
    }
}
