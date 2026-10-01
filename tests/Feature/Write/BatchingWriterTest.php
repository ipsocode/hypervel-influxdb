<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature\Write;

use GuzzleHttp\Psr7\Response;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Support\Facades\Exceptions;
use InfluxDB2\ApiException;
use InfluxDB2\Point;
use InvalidArgumentException;
use Ipsocode\InfluxDB\Tests\Concerns\MocksWrites;
use Ipsocode\InfluxDB\Tests\Fixtures\RecordsFailures;
use Ipsocode\InfluxDB\Tests\TestCase;
use Ipsocode\InfluxDB\Write\Batch;
use Ipsocode\InfluxDB\Write\BatchingWriter;
use Ipsocode\InfluxDB\Write\BatchOptions;
use Ipsocode\InfluxDB\Write\BatchWriteException;
use Ipsocode\InfluxDB\Write\BufferFullException;
use Mockery;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

/**
 * The batching writer inside a coroutine; BatchingWriterOutsideCoroutineTest covers it outside one.
 *
 * The suite's tests run in a coroutine, so here a full batch is sent from a
 * coroutine of its own and the flush interval is kept by a timer, as in a
 * worker. Without a delay, the mocked transport answers without yielding,
 * so such a send has finished by the time the write that started it returns.
 */
class BatchingWriterTest extends TestCase
{
    use MocksWrites;

    public function testPointsAreBufferedUntilTheirBatchHoldsBatchSizeLines(): void
    {
        $writer = $this->writer(['batchSize' => 3], self::accepted());

        $writer->write(Point::measurement('cpu')->addField('load', 1)->time(1));
        $writer->write(Point::measurement('cpu')->addField('load', 2)->time(2));

        $this->assertSame([], $this->history);
        $this->assertSame(2, $writer->getPendingPoints());

        $writer->write(Point::measurement('cpu')->addField('load', 3)->time(3));

        $this->assertSame([['cpu load=1i 1', 'cpu load=2i 2', 'cpu load=3i 3']], $this->sentBatches());
        $this->assertSame(['/api/v2/write?org=main-org&bucket=main-bucket&precision=ns'], $this->sentTo());
        $this->assertSame(0, $writer->getPendingPoints());
    }

    public function testABatchIsSentBeforeALineWouldTakeItPastBatchSizeMb(): void
    {
        // Each line is 13 bytes, so two and the newline between them are 27.
        $writer = $this->writer(['batchBytes' => 30], self::accepted(2));

        $writer->write('cpu load=1i 1');
        $writer->write('cpu load=2i 2');

        $this->assertSame([], $this->history);

        $writer->write('cpu load=3i 3');

        $this->assertSame([['cpu load=1i 1', 'cpu load=2i 2']], $this->sentBatches());
        $this->assertSame(1, $writer->getPendingPoints());
    }

    public function testABatchThatReachesBatchSizeMbIsSentAtOnce(): void
    {
        $writer = $this->writer(['batchBytes' => 27], self::accepted());

        $writer->write('cpu load=1i 1');
        $writer->write('cpu load=2i 2');

        $this->assertSame([['cpu load=1i 1', 'cpu load=2i 2']], $this->sentBatches());
        $this->assertSame(0, $writer->getPendingPoints());
    }

    public function testALineLargerThanBatchSizeMbIsSentAlone(): void
    {
        $writer = $this->writer(['batchBytes' => 10], self::accepted());

        $writer->write('cpu load=1i 1');

        $this->assertSame([['cpu load=1i 1']], $this->sentBatches());
    }

    public function testLinesThatWouldTakeABatchPastBatchSizeStartTheNextOne(): void
    {
        $writer = $this->writer(['batchSize' => 3], self::accepted(2));

        $writer->write("cpu load=1i 1\ncpu load=2i 2");
        $writer->write("cpu load=3i 3\ncpu load=4i 4");

        $this->assertSame([['cpu load=1i 1', 'cpu load=2i 2']], $this->sentBatches());
        $this->assertSame(2, $writer->getPendingPoints());
    }

    public function testAStringOfLineProtocolIsNeverSplit(): void
    {
        $writer = $this->writer(['batchSize' => 2], self::accepted());

        $writer->write("cpu load=1i 1\ncpu load=2i 2\ncpu load=3i 3\n");

        $this->assertSame([['cpu load=1i 1', 'cpu load=2i 2', 'cpu load=3i 3']], $this->sentBatches());
    }

    public function testAListOfPointsIsBufferedPointByPoint(): void
    {
        $writer = $this->writer(['batchSize' => 2], self::accepted(2));

        $writer->write([
            Point::measurement('cpu')->addField('load', 1)->time(1),
            null,
            ['name' => 'cpu', 'fields' => ['load' => 2], 'time' => 2],
            'cpu load=3i 3',
        ]);

        $this->assertSame([['cpu load=1i 1', 'cpu load=2i 2']], $this->sentBatches());
        $this->assertSame(1, $writer->getPendingPoints());
    }

    public function testWritesAreSerialisedAsASynchronousWriteApiSerialisesThem(): void
    {
        $writer = $this->writer(responses: self::accepted(), options: ['tags' => ['env' => 'test']]);

        $writer->write(Point::measurement('cpu')->addTag('host', 'web1')->addField('load', 0.5)->time(1));
        $writer->write(['name' => 'mem', 'fields' => ['used' => 3], 'time' => 2]);
        $writer->write('disk free=4i 3');
        $writer->flush();

        $this->assertSame([['cpu,env=test,host=web1 load=0.5 1', 'mem,env=test used=3i 2', 'disk free=4i 3']], $this->sentBatches());
    }

    public function testPointsAreBatchedByBucketOrgAndPrecision(): void
    {
        $writer = $this->writer(responses: self::accepted(3));

        $writer->write('cpu load=1i 1');
        $writer->write('cpu load=2i 2', 's');
        $writer->write('cpu load=3i 3', null, 'other-bucket', 'other-org');
        $writer->write('cpu load=4i 4');
        $writer->flush();

        $this->assertSame([
            '/api/v2/write?org=main-org&bucket=main-bucket&precision=ns',
            '/api/v2/write?org=main-org&bucket=main-bucket&precision=s',
            '/api/v2/write?org=other-org&bucket=other-bucket&precision=ns',
        ], $this->sentTo());
        $this->assertSame([['cpu load=1i 1', 'cpu load=4i 4'], ['cpu load=2i 2'], ['cpu load=3i 3']], $this->sentBatches());
    }

    public function testWriteRawBuffersLineProtocolForTheConnectionsBucketOrgAndPrecision(): void
    {
        $writer = $this->writer(responses: self::accepted());

        $writer->writeRaw("cpu load=1i 1\ncpu load=2i 2\n");

        $this->assertSame(2, $writer->getPendingPoints());

        $writer->flush();

        $this->assertSame(['/api/v2/write?org=main-org&bucket=main-bucket&precision=ns'], $this->sentTo());
        $this->assertSame([['cpu load=1i 1', 'cpu load=2i 2']], $this->sentBatches());
    }

    public function testWriteRawRefusesAnEmptyBucket(): void
    {
        $writer = new BatchingWriter(
            ['url' => 'http://localhost:8086', 'token' => 'main-token', 'bucket' => 'main-bucket', 'org' => 'main-org', 'precision' => 'ns'],
            'main',
            new BatchOptions(100, 1_000_000, 60.0, 1_000),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The 'bucket' should be defined as argument or default option");

        $writer->writeRaw('cpu load=1i 1', null, ' ');
    }

    public function testAWriteWithoutLinesBuffersNothing(): void
    {
        $writer = $this->writer();

        $writer->write('');
        $writer->write([]);
        $writer->write("\n");
        $writer->write(Point::measurement('cpu'));
        $writer->writeRaw("\n\n");
        $writer->flush();

        $this->assertSame(0, $writer->getPendingPoints());
        $this->assertSame([], $this->history);
    }

    public function testFlushSendsTheBufferOnce(): void
    {
        $writer = $this->writer(responses: self::accepted());

        $writer->write('cpu load=1i 1');
        $writer->write('cpu load=2i 2');
        $writer->flush();
        $writer->flush();

        $this->assertSame([['cpu load=1i 1', 'cpu load=2i 2']], $this->sentBatches());
        $this->assertSame(0, $writer->getPendingPoints());
    }

    public function testCloseFlushesTheBufferAndMarksTheWriterClosed(): void
    {
        $writer = $this->writer(responses: self::accepted());

        $writer->write('cpu load=1i 1');
        $writer->close();

        $this->assertTrue($writer->closed);
        $this->assertSame([['cpu load=1i 1']], $this->sentBatches());
    }

    public function testTheBufferIsSentOnceTheFlushIntervalHasPassedSinceItsFirstPoint(): void
    {
        $writer = $this->writer(['flushInterval' => 0.5], self::accepted(2));

        $writer->write('cpu load=1i 1');
        usleep(100_000);
        $writer->write('cpu load=2i 2');

        $this->assertSame([], $this->history);

        $this->waitUntil(fn (): bool => count($this->history) === 1);

        $this->assertSame([['cpu load=1i 1', 'cpu load=2i 2']], $this->sentBatches());
        $this->assertSame(0, $writer->getPendingPoints());

        $writer->write('cpu load=3i 3');

        $this->waitUntil(fn (): bool => count($this->history) === 2);

        $this->assertSame([['cpu load=1i 1', 'cpu load=2i 2'], ['cpu load=3i 3']], $this->sentBatches());
    }

    public function testAFlushCancelsTheFlushTimer(): void
    {
        $writer = $this->writer(['flushInterval' => 0.3], self::accepted(2));

        $writer->write('cpu load=1i 1');
        $writer->flush();

        // A timer the flush left armed would still fire 0.3s after the first
        // point, and send the next one half an interval after it was written.
        usleep(150_000);
        $writtenAt = hrtime(true);
        $writer->write('cpu load=2i 2');

        $this->waitUntil(fn (): bool => count($this->history) === 2);

        $this->assertSame([['cpu load=1i 1'], ['cpu load=2i 2']], $this->sentBatches());
        $this->assertGreaterThanOrEqual(300_000_000, $this->sentAt[1] - $writtenAt, 'The second point was sent before a whole flush interval had passed since it was written.');
    }

    public function testTheWorkerExitSendsTheBuffer(): void
    {
        $writer = $this->writer(responses: self::accepted());

        $writer->write('cpu load=1i 1');

        CoordinatorManager::until(Constants::WORKER_EXIT)->resume();

        $this->waitUntil(fn (): bool => $this->history !== []);

        $this->assertSame([['cpu load=1i 1']], $this->sentBatches());
        $this->assertSame(0, $writer->getPendingPoints());
    }

    public function testAWriteWhileTheWorkerExitsIsSentRightAway(): void
    {
        $writer = $this->writer(responses: self::accepted(2));

        CoordinatorManager::until(Constants::WORKER_EXIT)->resume();

        $writer->write('cpu load=1i 1');

        $this->assertSame([['cpu load=1i 1']], $this->sentBatches());

        $writer->write('cpu load=2i 2');

        $this->assertSame([['cpu load=1i 1'], ['cpu load=2i 2']], $this->sentBatches());
    }

    public function testAFullBatchIsSentWithoutHoldingUpTheWriteThatFilledIt(): void
    {
        $writer = $this->writer(['batchSize' => 2], self::accepted(2), delay: 50);

        $writer->write('cpu load=1i 1');
        $writer->write('cpu load=2i 2');

        // The batch is waiting on the server, in a coroutine of its own.
        $this->assertSame([], $this->history);
        $this->assertSame(2, $writer->getPendingPoints());

        $writer->write('cpu load=3i 3');
        $writer->write('cpu load=4i 4');
        $writer->flush();

        $this->assertEqualsCanonicalizing([['cpu load=1i 1', 'cpu load=2i 2'], ['cpu load=3i 3', 'cpu load=4i 4']], $this->sentBatches());
        $this->assertSame(0, $writer->getPendingPoints());
    }

    public function testWritesDuringAFlushGoToTheNextBatch(): void
    {
        $writer = $this->writer(responses: self::accepted(2), delay: 50);

        $writer->write('cpu load=1i 1');
        $writer->write('cpu load=2i 2');

        // The flush takes the batch, then yields while the server answers.
        $flushing = Coroutine::create(fn () => $writer->flush());

        $writer->write('cpu load=3i 3');

        Coroutine::join([$flushing]);

        $this->assertSame([['cpu load=1i 1', 'cpu load=2i 2']], $this->sentBatches());
        $this->assertSame(1, $writer->getPendingPoints());

        $writer->flush();

        $this->assertSame([['cpu load=1i 1', 'cpu load=2i 2'], ['cpu load=3i 3']], $this->sentBatches());
    }

    public function testTwoFlushesAtOnceNeverSendABatchTwice(): void
    {
        $writer = $this->writer(responses: self::accepted(2), delay: 50);

        $writer->write('cpu load=1i 1');
        $writer->write('cpu load=2i 2', null, 'other-bucket');

        // The first flush sends main-bucket's batch, and yields while the server answers.
        $flushing = Coroutine::create(fn () => $writer->flush());

        $writer->flush();

        Coroutine::join([$flushing]);

        $this->assertEqualsCanonicalizing([
            '/api/v2/write?org=main-org&bucket=main-bucket&precision=ns',
            '/api/v2/write?org=main-org&bucket=other-bucket&precision=ns',
        ], $this->sentTo());
        $this->assertSame(0, $writer->getPendingPoints());
    }

    public function testACancelledFlushLeavesTheBatchesItDidNotReachBuffered(): void
    {
        $writer = $this->writer(responses: [new CanceledException('Cancelled.'), new Response(204)]);

        $writer->write('cpu load=1i 1');
        $writer->write('cpu load=2i 2', null, 'other-bucket');

        try {
            $writer->flush();

            $this->fail('The cancellation was swallowed.');
        } catch (CanceledException) {
        }

        $this->assertSame(1, $writer->getPendingPoints());

        $writer->flush();

        // The first request is the one the cancellation interrupted.
        $this->assertSame([
            '/api/v2/write?org=main-org&bucket=main-bucket&precision=ns',
            '/api/v2/write?org=main-org&bucket=other-bucket&precision=ns',
        ], $this->sentTo());
        $this->assertSame(0, $writer->getPendingPoints());
    }

    public function testFlushWaitsForTheBatchesBeingSent(): void
    {
        $writer = $this->writer(['batchSize' => 1], self::accepted(), delay: 50);

        $writer->write('cpu load=1i 1');

        $this->assertSame([], $this->history);
        $this->assertSame(1, $writer->getPendingPoints());

        $writer->flush();

        $this->assertSame([['cpu load=1i 1']], $this->sentBatches());
        $this->assertSame(0, $writer->getPendingPoints());
    }

    public function testABatchIsRetriedAsTheClientRetriesASynchronousWrite(): void
    {
        $reported = Exceptions::fake();
        $writer = $this->writer(
            responses: [new Response(503, [], 'unavailable'), new Response(204)],
            writeOptions: ['maxRetries' => 1, 'retryInterval' => 1, 'maxRetryDelay' => 1],
        );

        $writer->write('cpu load=1i 1');
        $writer->flush();

        $this->assertSame([['cpu load=1i 1'], ['cpu load=1i 1']], $this->sentBatches());
        $reported->assertNothingReported();
    }

    public function testMaxRetriesDefaultsToThree(): void
    {
        $this->assertSame(BatchOptions::DEFAULT_MAX_RETRIES, $this->writer(writeOptions: ['maxRetries' => null])->writeOptions->maxRetries);
        $this->assertSame(1, $this->writer(writeOptions: ['maxRetries' => 1])->writeOptions->maxRetries);
    }

    public function testABatchThatCannotBeWrittenIsReportedInsteadOfThrown(): void
    {
        $reported = Exceptions::fake();
        $writer = $this->writer(['batchSize' => 2], [new Response(400, [], '{"code":"invalid","message":"unable to parse"}')]);

        $writer->write('cpu load=1i 1');
        $writer->write('cpu load=2i 2');

        $this->assertSame(0, $writer->getPendingPoints());
        $reported->assertReportedCount(1);
        $reported->assertReported(function (BatchWriteException $exception): bool {
            $this->assertStringStartsWith('InfluxDB connection [main] dropped a batch of 2 points (27 bytes) for bucket [main-bucket]: [400]', $exception->getMessage());
            $this->assertStringEndsWith('(unable to parse)', $exception->getMessage());
            $this->assertInstanceOf(ApiException::class, $exception->getPrevious());
            $this->assertSame(400, $exception->getPrevious()->getCode());

            return true;
        });
    }

    public function testOnFailureIsHandedTheBatchAndTheException(): void
    {
        Exceptions::fake();
        $failures = [];
        $writer = $this->writer(
            ['onFailure' => function (Batch $batch, BatchWriteException $exception) use (&$failures): void {
                $failures[] = [$batch, $exception];
            }],
            [new Response(503, [], 'unavailable')],
        );

        $writer->write('cpu load=1i 1', 's', 'other-bucket', 'other-org');
        $writer->flush();

        $this->assertCount(1, $failures);

        [[$batch, $exception]] = $failures;

        $this->assertSame(
            ['main', 'other-bucket', 'other-org', 's', 'cpu load=1i 1', 1, 13],
            [$batch->connection, $batch->bucket, $batch->org, $batch->precision, $batch->payload, $batch->points, $batch->bytes()],
        );
        $this->assertSame($batch, $exception->batch);
        $this->assertSame('InfluxDB connection [main] dropped a batch of 1 point (13 bytes) for bucket [other-bucket]: [503] Error connecting to the API (http://localhost:8086/api/v2/write?org=other-org&bucket=other-bucket&precision=s)(unavailable)', $exception->getMessage());
    }

    public function testOnFailureMayNameAnInvokableClass(): void
    {
        Exceptions::fake();
        $this->app->instance(RecordsFailures::class, $recorder = new RecordsFailures);
        $writer = $this->writer(['onFailure' => RecordsFailures::class], [new Response(500)]);

        $writer->write('cpu load=1i 1');
        $writer->flush();

        $this->assertSame(['cpu load=1i 1'], array_map(static fn (Batch $batch): string => $batch->payload, $recorder->batches));
    }

    public function testAnOnFailureThatThrowsIsReportedToo(): void
    {
        $reported = Exceptions::fake();
        $writer = $this->writer(['onFailure' => static function (): never {
            throw new RuntimeException('The spill failed.');
        }], [new Response(500)]);

        $writer->write('cpu load=1i 1');
        $writer->flush();

        $reported->assertReportedCount(2);
        $reported->assertReported(BatchWriteException::class);
        $reported->assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'The spill failed.');
    }

    public function testAFailureTheExceptionHandlerCannotReportIsLogged(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'influxdb');
        $previous = ini_set('error_log', $log);

        $handler = Mockery::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->andThrow(new RuntimeException('The handler is down.'));
        $this->app->instance(ExceptionHandler::class, $handler);

        try {
            $writer = $this->writer(responses: [new Response(500)]);

            $writer->write('cpu load=1i 1');
            $writer->flush();

            $this->assertStringContainsString('InfluxDB connection [main] dropped a batch of 1 point (13 bytes) for bucket [main-bucket]', (string) file_get_contents($log));
        } finally {
            ini_set('error_log', (string) $previous);
            unlink($log);
        }
    }

    public function testACancelledSendIsThrownOnRatherThanReported(): void
    {
        $reported = Exceptions::fake();
        $writer = $this->writer(responses: [new CanceledException('Cancelled.')]);

        $writer->write('cpu load=1i 1');

        try {
            $writer->flush();

            $this->fail('The cancellation was swallowed.');
        } catch (CanceledException $exception) {
            $this->assertSame('Cancelled.', $exception->getMessage());
        }

        $reported->assertNothingReported();
        $this->assertSame(0, $writer->getPendingPoints());
    }

    public function testPastMaxBufferedAWriteSendsTheBufferBeforeItReturns(): void
    {
        $writer = $this->writer(['maxBuffered' => 2], self::accepted());

        $writer->write('cpu load=1i 1');
        $writer->write('cpu load=2i 2');

        $this->assertSame([], $this->history);

        $writer->write('cpu load=3i 3');

        $this->assertSame([['cpu load=1i 1', 'cpu load=2i 2', 'cpu load=3i 3']], $this->sentBatches());
        $this->assertSame(0, $writer->getPendingPoints());
    }

    public function testPastMaxBufferedAWriteIsRefusedWhenOverflowIsRefuse(): void
    {
        $writer = $this->writer(['maxBuffered' => 2, 'overflow' => BatchOptions::REFUSE], self::accepted());

        $writer->write('cpu load=1i 1');
        $writer->write('cpu load=2i 2');

        try {
            $writer->write("cpu load=3i 3\ncpu load=4i 4");

            $this->fail('The write was not refused.');
        } catch (BufferFullException $exception) {
            $this->assertSame('InfluxDB connection [main] refused 2 points: 2 are waiting to be sent, and its maxBuffered is 2.', $exception->getMessage());
            $this->assertSame(['main', 2, 2, 2], [$exception->connection, $exception->points, $exception->waiting, $exception->maxBuffered]);
        }

        $this->assertSame(2, $writer->getPendingPoints());
        $this->assertSame([], $this->history);
    }

    public function testPointsBeingSentCountTowardMaxBuffered(): void
    {
        $writer = $this->writer(['batchSize' => 2, 'maxBuffered' => 3, 'overflow' => BatchOptions::REFUSE], self::accepted(3), delay: 50);

        $writer->write('cpu load=1i 1');
        $writer->write('cpu load=2i 2');
        $writer->write('cpu load=3i 3');

        try {
            $writer->write('cpu load=4i 4');

            $this->fail('The write was not refused.');
        } catch (BufferFullException $exception) {
            $this->assertSame('InfluxDB connection [main] refused 1 point: 3 are waiting to be sent, and its maxBuffered is 3.', $exception->getMessage());
        }

        $writer->flush();
        $writer->write('cpu load=4i 4');

        $this->assertSame(1, $writer->getPendingPoints());
    }

    public function testGetBatchOptionsReturnsTheOptionsItRunsWith(): void
    {
        $options = new BatchOptions(100, 1_000_000, 60.0, 1_000);

        $writer = new BatchingWriter(
            ['url' => 'http://localhost:8086', 'token' => 'main-token', 'bucket' => 'main-bucket', 'org' => 'main-org', 'precision' => 'ns'],
            'main',
            $options,
        );

        $this->assertSame($options, $writer->getBatchOptions());
    }

    /**
     * Yield until the condition holds, for up to two seconds.
     */
    private function waitUntil(callable $condition): void
    {
        $deadline = microtime(true) + 2;

        while (! $condition() && microtime(true) < $deadline) {
            usleep(5_000);
        }
    }
}
