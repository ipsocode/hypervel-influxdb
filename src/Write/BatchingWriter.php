<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Write;

use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Coordinator\Coordinator;
use Hypervel\Coordinator\Timer;
use Hypervel\Coroutine\Coroutine;
use InfluxDB2\WriteApi;
use InfluxDB2\WriteType;
use Swoole\Coroutine\CanceledException;
use Throwable;

/**
 * A WriteApi that buffers points in the worker and sends them in batches.
 *
 * Upstream's own WriteType::BATCHING sends a batch only once `batchSize`
 * writes have queued, with no timer; loses what is queued when the worker
 * stops unless something closes it; throws a batch that fails into
 * whichever write filled it; and holds any number of points. Its queue is
 * private, so this replaces it rather than wrapping it. The manager makes
 * one for each connection whose `writeType` is BATCHING, in place of
 * upstream's, so writeApi() still returns a WriteApi.
 *
 * A write() is serialised exactly as a synchronous WriteApi serialises it:
 * the parent's write() runs as SYNCHRONOUS, which is why `writeOptions`
 * reports it, and hands its line protocol to writeRaw(), which buffers it
 * instead of posting it. Default `tags`, precision, bucket and org apply as
 * they do to synchronous writes. A list of points is buffered point by
 * point, so a batch can end between any two of them; a string of line
 * protocol is kept whole.
 *
 * Points are grouped by bucket, org and precision, one batch for each, since
 * a request writes to one of each. A batch is sent:
 *
 * - once it holds `batchSize` lines or `batchSizeMb`, whichever comes first,
 *   in a coroutine of its own, so the write that filled it waits neither for
 *   the request nor for its retries;
 * - `flushInterval` seconds after the buffer took its first point, by a
 *   Hypervel Timer armed then;
 * - when flush() or close() is called, in the calling coroutine;
 * - when the worker exits: the timer waits on the WORKER_EXIT coordinator,
 *   which wakes it early, so the buffer is sent before the worker stops.
 *
 * A batch that still fails after the client's retries is reported through
 * the exception handler as a BatchWriteException and handed to `onFailure`,
 * and never thrown at a write(). Past `maxBuffered` points, buffered or
 * being sent, write() sends the buffer itself before it returns, so writers
 * slow to the pace the server takes, or, with `overflow` set to `refuse`,
 * throws a BufferFullException.
 *
 * Batches are taken out of the buffer before a send yields, so writes that
 * land meanwhile start new batches: no two sends carry the same point.
 *
 * Outside a coroutine there is none to arm a timer in or send from, so a
 * batch is sent by the write that fills it, and the buffer by the first
 * write that finds it older than `flushInterval`.
 */
class BatchingWriter extends WriteApi
{
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
     * @param array<string, mixed> $options the connection's client options
     * @param string $connection the connection's name, for its batches and messages
     * @param BatchOptions $batching when a batch is sent, and what happens when it cannot be
     * @param null|array<string, mixed> $writeOptions upstream's write options, whose retry settings apply to each batch
     * @param null|Timer $timer the timer to arm the flush interval on
     */
    public function __construct(
        array $options,
        protected string $connection,
        protected BatchOptions $batching,
        ?array $writeOptions = null,
        ?Timer $timer = null,
    ) {
        $writeOptions['writeType'] = WriteType::SYNCHRONOUS;
        $writeOptions['maxRetries'] ??= BatchOptions::DEFAULT_MAX_RETRIES;

        parent::__construct($options, $writeOptions);

        $this->timer = $timer ?? new Timer;
    }

    /**
     * Buffer points to be sent in a batch.
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
     * The buffer is sent from the calling coroutine, a batch at a time: each
     * stays in the buffer until its turn, so one another coroutine takes
     * meanwhile is not sent twice, and one this coroutine never reaches, if
     * it is cancelled, is still there to send. A batch that fails is
     * reported and handed to `onFailure`, as any other, not thrown.
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
     * Flush the buffer, as upstream's close() flushes its batches.
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
     * Send a batch through the client, with its retries; report it if it still fails.
     */
    protected function send(Batch $batch): void
    {
        try {
            $failure = self::attempt(fn () => parent::writeRaw($batch->payload, $batch->precision, $batch->bucket, $batch->org));

            if ($failure !== null) {
                $this->fail($batch, $failure);
            }
        } finally {
            $this->sending -= $batch->points;
        }
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
     * Report an exception through the application's exception handler, or, failing that, to the error log.
     */
    protected function report(Throwable $exception): void
    {
        if (self::attempt(fn () => Container::getInstance()->make(ExceptionHandler::class)->report($exception)) !== null) {
            error_log((string) $exception);
        }
    }

    /**
     * Arm the timer that flushes the buffer once `flushInterval` has passed, unless one is armed.
     *
     * Outside a coroutine there is no timer to arm, so the write that finds
     * the buffer older than the interval flushes it instead.
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

    /**
     * Run a callback, and return what it throws instead of throwing it.
     *
     * A coroutine's cancellation is thrown on, as Hypervel expects, rather
     * than taken for a failure.
     */
    private static function attempt(callable $callback): ?Throwable
    {
        try {
            $callback();
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return $exception;
        }

        return null;
    }
}
