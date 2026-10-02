<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature\Write;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Hypervel\Support\Facades\Exceptions;
use InfluxDB2\ApiException;
use InfluxDB2\Point;
use InfluxDB2\WriteType;
use InvalidArgumentException;
use Ipsocode\InfluxDB\Tests\Concerns\MocksWrites;
use Ipsocode\InfluxDB\Tests\TestCase;
use Ipsocode\InfluxDB\Write\Availability;
use Ipsocode\InfluxDB\Write\Batch;
use Ipsocode\InfluxDB\Write\BatchWriteException;
use Ipsocode\InfluxDB\Write\Failover;
use Ipsocode\InfluxDB\Write\FailoverException;
use Ipsocode\InfluxDB\Write\FailoverWriter;
use Swoole\Coroutine\CanceledException;
use Throwable;

/**
 * Writes falling back to other connections: a BatchingWriter's batches, and the synchronous FailoverWriter's writes.
 *
 * The `main` connection's transport answers first; a fallback's is named after it, so the hosts
 * the requests went through say which connection took each write.
 */
class FailoverTest extends TestCase
{
    use MocksWrites;

    private const string MAIN = 'localhost /api/v2/write?org=main-org&bucket=main-bucket&precision=ns';

    private const string BACKUP = 'backup.localhost /api/v2/write?org=backup-org&bucket=backup-bucket&precision=ns';

    public function testAWriteTheConnectionTakesIsNotFailedOver(): void
    {
        $reported = Exceptions::fake();
        $writer = $this->writer(responses: self::accepted(), failover: $this->failover(['backup' => []]));

        $writer->write('cpu load=1i 1');
        $writer->flush();

        $this->assertSame([self::MAIN], $this->sentThrough());
        $this->assertSame([], $writer->getFailover()->getAvailability()->unavailable());
        $reported->assertNothingReported();
    }

    public function testABatchTheConnectionCannotTakeGoesToTheFirstFallbackThatCan(): void
    {
        $reported = Exceptions::fake();
        $failover = $this->failover(['backup' => self::accepted()]);
        $writer = $this->writer(responses: [new Response(503, [], 'unavailable')], failover: $failover);

        $writer->write('cpu load=1i 1');
        $writer->flush();

        $this->assertSame([self::MAIN, self::BACKUP], $this->sentThrough());
        $this->assertSame([['cpu load=1i 1'], ['cpu load=1i 1']], $this->sentBatches());
        $this->assertSame(0, $writer->getPendingPoints());
        $this->assertFalse($failover->getAvailability()->isAvailable('main'));
        $this->assertTrue($failover->getAvailability()->isAvailable('backup'));
        $reported->assertReportedCount(1);
        $reported->assertReported(function (FailoverException $exception): bool {
            $this->assertSame(
                'InfluxDB connection [main] failed to write 1 point (13 bytes) for bucket [main-bucket], which connection [backup] took: '
                . '[main] [503] Error connecting to the API (http://localhost:8086/api/v2/write?org=main-org&bucket=main-bucket&precision=ns)(unavailable).',
                $exception->getMessage(),
            );
            $this->assertSame('backup', $exception->takenBy);
            $this->assertInstanceOf(ApiException::class, $exception->getPrevious());

            return true;
        });
    }

    public function testAWriteAddressedToAnotherBucketOrOrgKeepsThemOnTheFallback(): void
    {
        Exceptions::fake();
        $writer = $this->writer(responses: [new Response(503)], failover: $this->failover(['backup' => self::accepted()]));

        $writer->write('cpu load=1i 1', 's', 'other-bucket', 'other-org');
        $writer->flush();

        $this->assertSame([
            'localhost /api/v2/write?org=other-org&bucket=other-bucket&precision=s',
            'backup.localhost /api/v2/write?org=other-org&bucket=other-bucket&precision=s',
        ], $this->sentThrough());
    }

    public function testAServerThatCannotBeReachedIsFailedOver(): void
    {
        $reported = Exceptions::fake();
        $writer = $this->writer(
            responses: [new ConnectException('Connection refused', new Request('POST', 'http://localhost:8086/api/v2/write'))],
            failover: $this->failover(['backup' => self::accepted()]),
        );

        $writer->write('cpu load=1i 1');
        $writer->flush();

        $this->assertSame([self::MAIN, self::BACKUP], $this->sentThrough());
        $reported->assertReported(function (FailoverException $exception): bool {
            $this->assertInstanceOf(ApiException::class, $exception->getPrevious());
            $this->assertSame(0, $exception->getPrevious()->getCode());
            $this->assertStringContainsString('[main] [0] Connection refused', $exception->getMessage());

            return true;
        });
    }

    public function testAWriteTheConnectionRefusesForItsOwnFaultIsNotFailedOver(): void
    {
        $reported = Exceptions::fake();
        $failover = $this->failover(['backup' => self::accepted()]);
        $writer = $this->writer(responses: [new Response(400, [], '{"code":"invalid","message":"unable to parse"}')], failover: $failover);

        $writer->write('cpu load=1i 1');
        $writer->flush();

        $this->assertSame([self::MAIN], $this->sentThrough());
        $this->assertTrue($failover->getAvailability()->isAvailable('main'));
        $reported->assertReportedCount(1);
        $reported->assertReported(function (BatchWriteException $exception): bool {
            $this->assertStringEndsWith('for bucket [main-bucket]: [400] Error connecting to the API (http://localhost:8086/api/v2/write?org=main-org&bucket=main-bucket&precision=ns)(unable to parse)', $exception->getMessage());
            $this->assertInstanceOf(ApiException::class, $exception->getPrevious());

            return true;
        });
    }

    public function testAFallbackThatRefusesTheWriteIsPassedOverWithoutACooldown(): void
    {
        $reported = Exceptions::fake();
        $failover = $this->failover(['backup' => [new Response(400, [], 'bad')], 'archive' => self::accepted()]);
        $writer = $this->writer(responses: [new Response(503, [], 'unavailable')], failover: $failover);

        $writer->write('cpu load=1i 1');
        $writer->flush();

        $this->assertSame([self::MAIN, self::BACKUP, 'archive.localhost /api/v2/write?org=archive-org&bucket=archive-bucket&precision=ns'], $this->sentThrough());
        $this->assertSame(['main'], array_keys($failover->getAvailability()->unavailable()));
        $reported->assertReported(function (FailoverException $exception): bool {
            $this->assertSame('archive', $exception->takenBy);
            $this->assertSame(['main', 'backup'], array_keys($exception->failures()));
            $this->assertStringEndsWith('; [backup] [400] Error connecting to the API (http://backup.localhost:8086/api/v2/write?org=backup-org&bucket=backup-bucket&precision=ns)(bad).', $exception->getMessage());

            return true;
        });
    }

    public function testABatchNoConnectionTakesIsDroppedWithWhatEachConnectionDid(): void
    {
        $reported = Exceptions::fake();
        $failures = [];
        $failover = $this->failover(['backup' => [new Response(502, [], 'bad gateway')]]);
        $writer = $this->writer(
            ['onFailure' => function (Batch $batch, BatchWriteException $exception) use (&$failures): void {
                $failures[] = $exception;
            }],
            [new Response(503, [], 'unavailable')],
            failover: $failover,
        );

        $writer->write('cpu load=1i 1');
        $writer->flush();

        $this->assertSame([self::MAIN, self::BACKUP], $this->sentThrough());
        $this->assertSame(0, $writer->getPendingPoints());
        $this->assertSame(['main', 'backup'], array_keys($failover->getAvailability()->unavailable()));
        $reported->assertReportedCount(1);
        $reported->assertReported(BatchWriteException::class);
        $this->assertCount(1, $failures);
        $this->assertSame(
            'InfluxDB connection [main] dropped a batch of 1 point (13 bytes) for bucket [main-bucket], which none of its fallbacks took: '
            . '[main] [503] Error connecting to the API (http://localhost:8086/api/v2/write?org=main-org&bucket=main-bucket&precision=ns)(unavailable); '
            . '[backup] [502] Error connecting to the API (http://backup.localhost:8086/api/v2/write?org=backup-org&bucket=backup-bucket&precision=ns)(bad gateway).',
            $failures[0]->getMessage(),
        );
        $this->assertInstanceOf(FailoverException::class, $failures[0]->getPrevious());
        $this->assertSame(['main', 'backup'], array_keys($failures[0]->getPrevious()->failures()));
    }

    public function testAConnectionThatFailedIsSkippedForTheCooldown(): void
    {
        $reported = Exceptions::fake();
        $failover = $this->failover(['backup' => self::accepted(2)], cooldown: 0.2);
        $writer = $this->writer(['batchSize' => 1], [new Response(503, [], 'unavailable'), new Response(204)], failover: $failover);

        // Each full batch is sent from a coroutine of its own, which a failure makes yield: flush() waits for it.
        $writer->write('cpu load=1i 1');
        $writer->flush();

        $this->assertSame([self::MAIN, self::BACKUP], $this->sentThrough());
        $this->assertGreaterThan(0.0, $failover->getAvailability()->unavailableFor('main'));
        $reported->assertReportedCount(1);

        $writer->write('cpu load=2i 2');
        $writer->flush();

        // The second batch skipped main, still cooling down, and went to backup without a new failure to report.
        $this->assertSame([self::MAIN, self::BACKUP, self::BACKUP], $this->sentThrough());
        $reported->assertReportedCount(1);

        usleep(250_000);

        $writer->write('cpu load=3i 3');
        $writer->flush();

        $this->assertSame([self::MAIN, self::BACKUP, self::BACKUP, self::MAIN], $this->sentThrough());
        $this->assertTrue($failover->getAvailability()->isAvailable('main'));
        $reported->assertReportedCount(1);
    }

    public function testWhenEveryConnectionIsCoolingDownTheBatchIsDroppedWithoutARequest(): void
    {
        $reported = Exceptions::fake();
        $availability = new Availability;
        $availability->markUnavailable('main', 30);
        $availability->markUnavailable('backup', 30);
        $writer = $this->writer(['batchSize' => 1], failover: $this->failover(['backup' => []], availability: $availability));

        $writer->write('cpu load=1i 1');

        $this->assertSame([], $this->history);
        $this->assertSame(0, $writer->getPendingPoints());
        $reported->assertReported(function (BatchWriteException $exception): bool {
            $this->assertMatchesRegularExpression(
                '/^InfluxDB connection \[main\] dropped a batch of 1 point \(13 bytes\) for bucket \[main-bucket\], which none of its fallbacks took: '
                . '\[main\] skipped for another \d+\.\d seconds; \[backup\] skipped for another \d+\.\d seconds\.$/',
                $exception->getMessage(),
            );
            $this->assertInstanceOf(FailoverException::class, $exception->getPrevious());
            $this->assertNull($exception->getPrevious()->getPrevious());
            $this->assertSame([], $exception->getPrevious()->failures());
            $this->assertSame(['main', 'backup'], array_keys($exception->getPrevious()->skipped()));

            return true;
        });
    }

    public function testAFallbackIsRetriedAsTheWriterRetries(): void
    {
        Exceptions::fake();
        $writer = $this->writer(
            responses: [new Response(503), new Response(503)],
            writeOptions: ['maxRetries' => 1, 'retryInterval' => 1, 'maxRetryDelay' => 1],
            failover: $this->failover(['backup' => [new Response(503), new Response(204)]]),
        );

        $writer->write('cpu load=1i 1');
        $writer->flush();

        $this->assertSame([self::MAIN, self::MAIN, self::BACKUP, self::BACKUP], $this->sentThrough());
        $this->assertSame(0, $writer->getPendingPoints());
    }

    public function testACancelledSendIsThrownOnBeforeAnyFallbackIsTried(): void
    {
        $reported = Exceptions::fake();
        $writer = $this->writer(responses: [new CanceledException('Cancelled.')], failover: $this->failover(['backup' => self::accepted()]));

        $writer->write('cpu load=1i 1');

        try {
            $writer->flush();

            $this->fail('The cancellation was swallowed.');
        } catch (CanceledException) {
        }

        $this->assertSame([self::MAIN], $this->sentThrough());
        $reported->assertNothingReported();
    }

    public function testASynchronousWriteFallsBackToo(): void
    {
        $reported = Exceptions::fake();
        $writer = $this->failoverWriter([new Response(503, [], 'unavailable')], $this->failover(['backup' => self::accepted()]));

        $writer->write(Point::measurement('cpu')->addField('load', 1)->time(1));

        $this->assertSame([self::MAIN, self::BACKUP], $this->sentThrough());
        $this->assertSame([['cpu load=1i 1'], ['cpu load=1i 1']], $this->sentBatches());
        $this->assertSame(WriteType::SYNCHRONOUS, $writer->writeOptions->writeType);
        $this->assertSame(['backup'], $writer->getFailover()->getFallbacks());
        $reported->assertReported(FailoverException::class);
    }

    public function testASynchronousWriteNoConnectionTakesThrowsTheFailover(): void
    {
        $writer = $this->failoverWriter([new Response(503, [], 'unavailable')], $this->failover(['backup' => [new Response(503, [], 'down')]]));

        try {
            $writer->writeRaw("cpu load=1i 1\n");

            $this->fail('The failure was swallowed.');
        } catch (FailoverException $exception) {
            $this->assertSame(
                'InfluxDB connection [main] failed to write 1 point (13 bytes) for bucket [main-bucket], and none of its fallbacks took them: '
                . '[main] [503] Error connecting to the API (http://localhost:8086/api/v2/write?org=main-org&bucket=main-bucket&precision=ns)(unavailable); '
                . '[backup] [503] Error connecting to the API (http://backup.localhost:8086/api/v2/write?org=backup-org&bucket=backup-bucket&precision=ns)(down).',
                $exception->getMessage(),
            );
            $this->assertInstanceOf(ApiException::class, $exception->getPrevious());
            $this->assertSame(503, $exception->getPrevious()->getCode());
        }

        $this->assertSame([self::MAIN, self::BACKUP], $this->sentThrough());
    }

    public function testASynchronousWriteTheConnectionRefusesThrowsTheClientsException(): void
    {
        $writer = $this->failoverWriter([new Response(400, [], 'bad')], $this->failover(['backup' => self::accepted()]));

        try {
            $writer->write('cpu load=1i 1');

            $this->fail('The refusal was swallowed.');
        } catch (ApiException $exception) {
            $this->assertSame(400, $exception->getCode());
        }

        $this->assertSame([self::MAIN], $this->sentThrough());
    }

    public function testTheFailoverWriterChecksTheBucketBeforeAnythingIsSent(): void
    {
        // Built without a transport: the client's check() prints the options, and cannot print one.
        $writer = new FailoverWriter(self::clientOptions('main', null), $this->failover(['backup' => []]), ['maxRetries' => 0]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The 'bucket' should be defined as argument or default option");

        $writer->writeRaw('cpu load=1i 1', null, ' ');
    }

    public function testAWriterGetsItsOwnCopyOfTheFailoverWithItsWriteOptions(): void
    {
        $failover = $this->failover(['backup' => []]);
        $writer = $this->failoverWriter([], $failover);

        $this->assertNotSame($failover, $writer->getFailover());
        $this->assertSame('main', $writer->getFailover()->getConnection());
        $this->assertSame($failover->getOptions(), $writer->getFailover()->getOptions());
        $this->assertSame($failover->getAvailability(), $writer->getFailover()->getAvailability());
        $this->assertSame(['backup'], $failover->getFallbacks());
        $this->assertNull($this->writer()->getFailover());
    }

    /**
     * A synchronous writer for the `main` connection whose transport answers with the given responses, with the client's retries off.
     *
     * @param list<Response|Throwable> $responses
     */
    private function failoverWriter(array $responses, Failover $failover): FailoverWriter
    {
        return new FailoverWriter(self::clientOptions('main', $this->transport($responses)), $failover, ['maxRetries' => 0]);
    }
}
