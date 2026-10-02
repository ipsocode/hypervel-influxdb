<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Write;

use Hypervel\Container\Container;
use Hypervel\Coordinator\Coordinator;
use Hypervel\Coordinator\Timer;
use Hypervel\Coroutine\Coroutine;
use InfluxDB2\WriteApi;
use InfluxDB2\WriteType;
use Ipsocode\InfluxDB\Write\Concerns\ReportsFailures;
use Throwable;

/**
 * A WriteApi that buffers the worker's points and sends them in batches.
 *
 * Its `writeOptions` report SYNCHRONOUS so that the parent's write() serialises
 * each point as a synchronous write does and hands the line protocol to
 * writeRaw(), which buffers it instead of posting it. A batch its connection
 * cannot take goes to the connection's fallbacks, when it has any.
 *
 * @see docs/writing.md#batching-writes
 * @see docs/internals.md#the-batching-writer
 */
class BatchingWriter extends WriteApi
{
    use ReportsFailures;

    /**
     * The batches being filled, keyed by the bucket, org and precision their points go to.
     *
     * @var array<string, array{bucket: string, org: string, precision: string, lines: list<string>, points: int, bytes: int}>
     */
    protected array $batches = [];

    /**
     * The points in the batches being filled.
     */
    protected int $buffered = 0;

    /**
     * The points in batches taken from the buffer whose send has not finished.
     */
    protected int $sending = 0;

    /**
     * When the buffer took its first point, in hrtime() nanoseconds, while it holds any.
     */
    protected ?int $bufferedSince = null;

    /**
     * The sends running in coroutines of their own, keyed by coroutine ID.
     *
     * Each one's coordinator is resumed when it finishes, which is what
     * flush() waits for.
     *
     * @var array<int, Coordinator>
     */
    protected array $sends = [];

    /**
     * The ID of the timer that flushes the buffer, while one is armed.
     */
    protected ?int $flushTimer = null;

    /**
     * Counts every time the flush timer is armed, fires or is cleared.
     *
     * Timer::after() runs its callback before it returns when the worker is
     * already exiting, and the count tells whether the ID it returns still
     * belongs to an armed timer.
     */
    protected int $flushTimerGeneration = 0;

    protected Timer $timer;

    /**
     * The connections a batch falls back to, with this writer's write options, when the connection has any.
     */
    protected ?Failover $failover;

    /**
     * @param array<string, mixed> $options the connection's client options
     * @param string $connection the connection's name, for its batches and messages
     * @param BatchOptions $batching when a batch is sent, and what happens when it cannot be
     * @param null|array<string, mixed> $writeOptions the client's write options, whose retry settings apply to each batch
     * @param null|Timer $timer the timer to arm the flush interval on
     * @param null|Failover $failover the connections a batch this connection cannot take falls back to
     */
    public function __construct(
        array $options,
        protected string $connection,
        protected BatchOptions $batching,
        ?array $writeOptions = null,
        ?Timer $timer = null,
        ?Failover $failover = null,
    ) {
        $writeOptions['writeType'] = WriteType::SYNCHRONOUS;
        $writeOptions['maxRetries'] ??= BatchOptions::DEFAULT_MAX_RETRIES;

        parent::__construct($options, $writeOptions);

        $this->timer = $timer ?? new Timer;
        $this->failover = $failover?->withWriteOptions($writeOptions);
    }

    /**
     * Buffer points to be sent in a batch.
     *
     * A list is buffered point by point, so a batch can end between any two
     * of its points.
     *
     * @param mixed $data a Point, a point as an array, a string of line protocol, or a list of any of them
     *
     * @throws BufferFullException when `overflow` is `refuse` and the points would take the buffer past `maxBuffered`
     */
    public function write($data, ?string $precision = null, ?string $bucket = null, ?string $org = null): void
    {
        if (is_array($data) && ! array_key_exists('name', $data)) {
            foreach ($data as $item) {
                if ($item !== null) {
                    $this->write($item, $precision, $bucket, $org);
                }
            }

            return;
        }

        parent::write($data, $precision, $bucket, $org);
    }

    /**
     * Buffer line protocol to be sent in a batch.
     *
     * Every line counts toward the batch's size, but the string is never
     * split between two batches.
     *
     * @throws BufferFullException when `overflow` is `refuse` and the lines would take the buffer past `maxBuffered`
     */
    public function writeRaw(string $data, ?string $precision = null, ?string $bucket = null, ?string $org = null): void
    {
        $precision ??= $this->options['precision'] ?? null;
        $bucket ??= $this->options['bucket'] ?? null;
        $org ??= $this->options['org'] ?? null;

        $this->check('precision', $precision);
        $this->check('bucket', $bucket);
        $this->check('org', $org);

        $this->buffer(rtrim($data, "\n"), (string) $precision, (string) $bucket, (string) $org);
    }

    /**
     * Send every buffered batch now, and wait for those already being sent.
     *
     * The calling coroutine sends a batch at a time, each left in the buffer
     * until its turn: one another coroutine takes meanwhile is not sent twice,
     * and one a cancelled flush never reaches is still there to send.
     */
    public function flush(): void
    {
        $this->cancelFlushTimer();

        foreach (array_keys($this->batches) as $key) {
            if (isset($this->batches[$key])) {
                $this->send($this->take($key));
            }
        }

        $this->awaitSends();
    }

    /**
     * Mark the writer closed and flush the buffer.
     */
    public function close(): void
    {
        $this->closed = true;

        $this->flush();
    }

    /**
     * Get when this writer sends a batch, and what happens when it cannot.
     */
    public function getBatchOptions(): BatchOptions
    {
        return $this->batching;
    }

    /**
     * Get the connections a batch falls back to, or null when this connection has none.
     */
    public function getFailover(): ?Failover
    {
        return $this->failover;
    }

    /**
     * Get the number of points not written yet: buffered, or in a batch being sent.
     */
    public function getPendingPoints(): int
    {
        return $this->buffered + $this->sending;
    }

    /**
     * Add line protocol to the batch for its bucket, org and precision, and send what that fills.
     *
     * @throws BufferFullException
     */
    protected function buffer(string $lines, string $precision, string $bucket, string $org): void
    {
        if ($lines === '') {
            return;
        }

        $points = substr_count($lines, "\n") + 1;
        $waiting = $this->buffered + $this->sending;
        $overflowing = $waiting + $points > $this->batching->maxBuffered;

        if ($overflowing && $this->batching->overflow === BatchOptions::REFUSE) {
            throw new BufferFullException($this->connection, $points, $waiting, $this->batching->maxBuffered);
        }

        $key = serialize([$bucket, $org, $precision]);
        $bytes = strlen($lines);

        // Lines that would take the batch past a limit start the next one, so no request outgrows them.
        if (isset($this->batches[$key])
            && ($this->batches[$key]['points'] + $points > $this->batching->batchSize
                || $this->batches[$key]['bytes'] + 1 + $bytes > $this->batching->batchBytes)) {
            $this->dispatch($this->take($key));
        }

        $this->batches[$key] ??= ['bucket' => $bucket, 'org' => $org, 'precision' => $precision, 'lines' => [], 'points' => 0, 'bytes' => -1];
        $this->batches[$key]['lines'][] = $lines;
        $this->batches[$key]['points'] += $points;
        $this->batches[$key]['bytes'] += 1 + $bytes;
        $this->buffered += $points;
        $this->bufferedSince ??= hrtime(true);

        if ($this->batches[$key]['points'] >= $this->batching->batchSize || $this->batches[$key]['bytes'] >= $this->batching->batchBytes) {
            $this->dispatch($this->take($key));
        }

        if ($overflowing) {
            $this->flush();
        } else {
            $this->scheduleFlush();
        }
    }

    /**
     * Take a batch out of the buffer, to be sent.
     *
     * It is taken before its send yields, so writes that land meanwhile start
     * new batches, and no two sends carry the same point.
     */
    protected function take(string $key): Batch
    {
        $batch = $this->batches[$key];

        unset($this->batches[$key]);

        $this->buffered -= $batch['points'];
        $this->sending += $batch['points'];

        if ($this->buffered === 0) {
            $this->bufferedSince = null;
        }

        return new Batch(
            $this->connection,
            $batch['bucket'],
            $batch['org'],
            $batch['precision'],
            implode("\n", $batch['lines']),
            $batch['points'],
        );
    }

    /**
     * Send a batch without holding up the write that filled it.
     *
     * In a coroutine of its own, or, outside any, right away.
     */
    protected function dispatch(Batch $batch): void
    {
        if (! Coroutine::inCoroutine()) {
            $this->send($batch);

            return;
        }

        Coroutine::create(function () use ($batch): void {
            $id = Coroutine::id();
            $done = $this->sends[$id] = new Coordinator;

            try {
                $this->send($batch);
            } finally {
                unset($this->sends[$id]);
                $done->resume();
            }
        });
    }

    /**
     * Send a batch through the client, with its retries, and its fallbacks; report it if it still fails.
     */
    protected function send(Batch $batch): void
    {
        try {
            $failure = self::attempt(fn () => $this->deliver($batch));

            if ($failure !== null) {
                $this->fail($batch, $failure);
            }
        } finally {
            $this->sending -= $batch->points;
        }
    }

    /**
     * Post a batch through this connection, or, when it cannot take it, through the first fallback that can.
     *
     * @throws Throwable the connection's own failure, or a FailoverException when no fallback took the batch either
     */
    protected function deliver(Batch $batch): void
    {
        $through = fn () => parent::writeRaw($batch->payload, $batch->precision, $batch->bucket, $batch->org);

        if ($this->failover === null) {
            $through();

            return;
        }

        $this->failover->write($batch->payload, $batch->precision, $batch->bucket, $batch->org, $through);
    }

    /**
     * Report a batch that could not be written, and hand it to `onFailure`.
     */
    protected function fail(Batch $batch, Throwable $cause): void
    {
        $exception = new BatchWriteException($batch, $cause);

        $this->report($exception);

        $onFailure = $this->batching->onFailure;

        if ($onFailure === null) {
            return;
        }

        $failure = self::attempt(fn () => (is_callable($onFailure) ? $onFailure : Container::getInstance()->make($onFailure))($batch, $exception));

        if ($failure !== null) {
            $this->report($failure);
        }
    }

    /**
     * Arm the timer that flushes the buffer once `flushInterval` has passed, unless one is armed.
     *
     * The timer also fires when the worker exits, so the buffer is sent before
     * the worker stops. Outside a coroutine there is no timer to arm, so the
     * write that finds the buffer older than the interval flushes it instead.
     */
    protected function scheduleFlush(): void
    {
        if ($this->bufferedSince === null) {
            return;
        }

        if (! Coroutine::inCoroutine()) {
            if (hrtime(true) - $this->bufferedSince >= $this->batching->flushInterval * 1_000_000_000) {
                $this->flush();
            }

            return;
        }

        if ($this->flushTimer !== null) {
            return;
        }

        $generation = ++$this->flushTimerGeneration;

        $timer = $this->timer->after($this->batching->flushInterval, function (): void {
            $this->flushTimer = null;
            ++$this->flushTimerGeneration;

            $this->flush();
        });

        if ($generation === $this->flushTimerGeneration) {
            $this->flushTimer = $timer;
        }
    }

    /**
     * Clear the flush timer, if one is armed.
     */
    protected function cancelFlushTimer(): void
    {
        if ($this->flushTimer === null) {
            return;
        }

        $this->timer->clear($this->flushTimer);

        $this->flushTimer = null;
        ++$this->flushTimerGeneration;
    }

    /**
     * Wait for the batches being sent in coroutines of their own, but the calling one's.
     */
    protected function awaitSends(): void
    {
        if (! Coroutine::inCoroutine()) {
            return;
        }

        $sends = $this->sends;

        unset($sends[Coroutine::id()]);

        foreach ($sends as $done) {
            $done->yield();
        }
    }
}
