<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Write;

use Closure;
use InfluxDB2\Client;
use InfluxDB2\WriteApi;
use InfluxDB2\WriteType;
use InvalidArgumentException;
use Swoole\Coroutine\CanceledException;
use Throwable;

/**
 * Writes the batches a connection's failure store kept, for InfluxDBManager's replayFailed() and writeFailed().
 *
 * The manager keeps one for the worker's life, so the synchronous WriteApi each
 * connection replays through lives as long; disconnect() has it forget one.
 *
 * @see src/docs/writing.md#replaying-failed-batches
 */
class FailedBatchReplayer
{
    /**
     * The memoized synchronous WriteApi instances that replay failed batches, keyed by connection name.
     *
     * @var array<string, WriteApi>
     */
    protected array $replayApis = [];

    /**
     * @param Closure(string): Client $connection resolves a connection's client by its name
     */
    public function __construct(
        protected Closure $connection,
    ) {
    }

    /**
     * Write the batches the failure store of the given connection's WriteApi kept, oldest first.
     *
     * A batch that fails again is stored again with one more attempt, and dropped and logged
     * once that reaches `write.maxAttempts`. Each batch is removed from the store only after
     * it was written, stored again or dropped, and a replay canceled mid-write leaves its batch
     * as it was. The replay ends at the first batch that fails
     * again, and leaves the later ones untouched for the next run.
     *
     * @return int the batches written
     *
     * @throws InvalidArgumentException when the WriteApi does not batch, or its `onFailure` is not a ReplayableFailureStore
     */
    public function replay(string $name, WriteApi $writeApi, int $limit): int
    {
        $store = $writeApi instanceof BatchingWriter ? $writeApi->getBatchOptions()->onFailure : null;

        if ($store instanceof QueueFailureStore) {
            throw new InvalidArgumentException("InfluxDB connection [{$name}] has no failure store that can replay its batches: its queue driver leaves the replaying to the queue's workers.");
        }

        if (! $store instanceof ReplayableFailureStore) {
            throw new InvalidArgumentException("InfluxDB connection [{$name}] has no failure store that can replay its batches: set write.onFailure to a ReplayableFailureStore.");
        }

        $maxAttempts = $writeApi->getBatchOptions()->maxAttempts;
        $written = 0;
        $stopped = false;

        try {
            $store->replay(function (FailedBatch $batch) use ($name, $store, $maxAttempts, &$written, &$stopped): void {
                if ($stopped) {
                    throw new ReplayStopped;
                }

                try {
                    $this->write($batch, $name);
                    ++$written;
                } catch (CanceledException $exception) {
                    // Canceled mid-write, the batch keeps its attempts and stays in the store.
                    throw $exception;
                } catch (Throwable $exception) {
                    $again = $batch->replayFailed($exception);

                    ($again->attempts >= $maxAttempts ? new LogFailureStore : $store)->store($again);
                    $stopped = true;
                }
            }, $limit);
        } catch (ReplayStopped) {
        }

        return $written;
    }

    /**
     * Write a failed batch through a synchronous WriteApi of the given connection, to the bucket, org and precision it was going to.
     *
     * The batch is sent once, with no retries of the client's, so each replay counts as one attempt.
     *
     * @throws Throwable when the write fails
     */
    public function write(FailedBatch $batch, string $name): void
    {
        $replayApi = $this->replayApis[$name] ??= ($this->connection)($name)->createWriteApi(['writeType' => WriteType::SYNCHRONOUS, 'maxRetries' => 0]);

        $replayApi->writeRaw($batch->payload, $batch->precision, $batch->bucket, $batch->org);
    }

    /**
     * Forget the synchronous WriteApi of the given connection, so the next write builds one on its current client.
     *
     * Boot or tests only. InfluxDBManager::disconnect() calls it; a coroutine replaying meanwhile keeps the previous WriteApi.
     */
    public function forget(string $name): void
    {
        unset($this->replayApis[$name]);
    }
}
