<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature\Write;

use Hypervel\Foundation\Events\Terminating;
use Hypervel\Support\Facades\Exceptions;
use InfluxDB2\WriteType;
use Ipsocode\InfluxDB\InfluxDBManager;
use Ipsocode\InfluxDB\Tests\Concerns\MocksWrites;
use Ipsocode\InfluxDB\Tests\TestCase;

/**
 * The batching writer outside any coroutine, as in a console command that runs without one, where there
 * is no timer: a write sends the batch it fills, or the buffer it finds older than the flush interval.
 */
class BatchingWriterOutsideCoroutineTest extends TestCase
{
    use MocksWrites;

    protected bool $runTestsInCoroutine = false;

    public function testTheWriteThatFillsABatchSendsIt(): void
    {
        $writer = $this->writer(['batchSize' => 2], self::accepted());

        $writer->write('cpu load=1i 1');

        $this->assertSame([], $this->history);

        $writer->write('cpu load=2i 2');

        $this->assertSame([['cpu load=1i 1', 'cpu load=2i 2']], $this->sentBatches());
        $this->assertSame(0, $writer->getPendingPoints());
    }

    public function testTheFirstWriteAfterTheFlushIntervalSendsTheBuffer(): void
    {
        $writer = $this->writer(['flushInterval' => 0.2], self::accepted());

        $writer->write('cpu load=1i 1');
        $writer->write('cpu load=2i 2');

        $this->assertSame([], $this->history);

        usleep(300_000);

        $writer->write('cpu load=3i 3');

        $this->assertSame([['cpu load=1i 1', 'cpu load=2i 2', 'cpu load=3i 3']], $this->sentBatches());
        $this->assertSame(0, $writer->getPendingPoints());
    }

    public function testFlushSendsTheBuffer(): void
    {
        $reported = Exceptions::fake();
        $writer = $this->writer(responses: self::accepted());

        $writer->write('cpu load=1i 1');
        $writer->flush();

        $this->assertSame([['cpu load=1i 1']], $this->sentBatches());
        $reported->assertNothingReported();
    }

    public function testTheConsoleApplicationTerminatingSendsWhatEveryConnectionHasBuffered(): void
    {
        $this->app->get('config')->set('influxdb.connections.main.write', ['writeType' => WriteType::BATCHING]);
        $this->app->get('config')->set('influxdb.connections.main.httpClient', $this->transport(self::accepted()));

        $this->app->get(InfluxDBManager::class)->writeApi()->write('cpu load=1i 1');

        // The console kernel terminates after the command's coroutine has ended.
        $this->app->get('events')->dispatch(new Terminating);

        $this->assertSame([['cpu load=1i 1']], $this->sentBatches());
    }
}
