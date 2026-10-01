<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature\Write;

use GuzzleHttp\Psr7\Response;
use Hypervel\Foundation\Events\Terminating;
use InfluxDB2\WriteApi;
use InfluxDB2\WriteType;
use InvalidArgumentException;
use Ipsocode\InfluxDB\Facades\InfluxDB;
use Ipsocode\InfluxDB\InfluxDBManager;
use Ipsocode\InfluxDB\Tests\Concerns\MocksWrites;
use Ipsocode\InfluxDB\Tests\TestCase;
use Ipsocode\InfluxDB\Write\BatchingWriter;
use Ipsocode\InfluxDB\Write\BatchOptions;

/**
 * Batching writes as an application turns them on: through a connection's `write` block, or the
 * write options given to `writeApi()`.
 */
class BatchingWritesTest extends TestCase
{
    use MocksWrites;

    public function testWriteApiIsABatchingWriterWhenTheConnectionAsksForBatching(): void
    {
        $this->batching('main');

        $manager = $this->app->get(InfluxDBManager::class);
        $writeApi = $manager->writeApi();

        $this->assertInstanceOf(BatchingWriter::class, $writeApi);
        $this->assertInstanceOf(WriteApi::class, $writeApi);
        $this->assertSame($writeApi, $manager->writeApi('main'));
        $this->assertNotInstanceOf(BatchingWriter::class, $manager->writeApi('analytics'));
    }

    public function testTheBatchLimitsFollowTheVersionTheConnectionNames(): void
    {
        $this->batching('main');
        $this->batching('analytics');
        $this->app->get('config')->set('influxdb.connections.analytics.version', 'v3');

        $manager = $this->app->get(InfluxDBManager::class);

        $this->assertEquals(new BatchOptions(5_000, 25_000_000, 1.0, 50_000), $manager->writeApi('main')->getBatchOptions());
        $this->assertEquals(new BatchOptions(10_000, 10_000_000, 1.0, 100_000), $manager->writeApi('analytics')->getBatchOptions());
    }

    public function testTheLimitsAreSetInTheConnectionsWriteBlock(): void
    {
        $this->batching('main', ['batchSize' => '2', 'batchSizeMb' => '0.001', 'flushInterval' => '0.5']);

        $writeApi = $this->app->get(InfluxDBManager::class)->writeApi();

        $this->assertEquals(new BatchOptions(2, 1_000, 0.5, 20), $writeApi->getBatchOptions());
    }

    public function testWriteOptionsGivenToWriteApiMayAskForBatching(): void
    {
        $writeApi = $this->app->get(InfluxDBManager::class)->writeApi('analytics', ['writeType' => WriteType::BATCHING, 'batchSize' => 50]);

        $this->assertInstanceOf(BatchingWriter::class, $writeApi);
        $this->assertSame(50, $writeApi->getBatchOptions()->batchSize);
    }

    public function testBatchingOptionsOutOfRangeFailWhenTheWriteApiIsFirstResolved(): void
    {
        $this->batching('main', ['batchSize' => 0]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('InfluxDB connection [main] has an invalid write.batchSize [0]; expected a whole number above zero.');

        $this->app->get(InfluxDBManager::class)->writeApi();
    }

    public function testBatchingOnAVersionThePackageDoesNotImplementFails(): void
    {
        $this->batching('main');
        $this->app->get('config')->set('influxdb.connections.main.version', 'v4');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('InfluxDB connection [main] has an unsupported version [v4]; expected one of v1, v2, v3.');

        $this->app->get(InfluxDBManager::class)->writeApi();
    }

    public function testFlushSendsWhatTheConnectionHasBuffered(): void
    {
        $this->batching('main', responses: self::accepted());

        InfluxDB::writeApi()->write('cpu load=1i 1');
        InfluxDB::writeApi()->write('cpu load=2i 2');

        $this->assertSame([], $this->history);

        InfluxDB::flush();

        $this->assertSame([['cpu load=1i 1', 'cpu load=2i 2']], $this->sentBatches());
    }

    public function testFlushHasNothingToDoForASynchronousOrUnresolvedWriteApi(): void
    {
        $manager = $this->app->get(InfluxDBManager::class);
        $manager->writeApi('analytics');

        $manager->flush('analytics');
        $manager->flush('main');

        $this->assertSame([], $this->history);
    }

    public function testFlushAllSendsWhatEveryConnectionHasBuffered(): void
    {
        $this->batching('main', responses: self::accepted());
        $this->batching('analytics', responses: self::accepted());

        $manager = $this->app->get(InfluxDBManager::class);
        $manager->writeApi('main')->write('cpu load=1i 1');
        $manager->writeApi('analytics')->write('cpu load=2i 2');

        $manager->flushAll();

        $this->assertSame(
            ['localhost /api/v2/write', 'analytics.localhost /api/v2/write'],
            array_map(static fn (array $entry): string => $entry['request']->getUri()->getHost() . ' ' . $entry['request']->getUri()->getPath(), $this->history),
        );
    }

    public function testDisconnectSendsTheBufferBeforeDroppingTheWriter(): void
    {
        $this->batching('main', responses: self::accepted());

        $manager = $this->app->get(InfluxDBManager::class);
        $writeApi = $manager->writeApi();
        $writeApi->write('cpu load=1i 1');

        $manager->disconnect();

        $this->assertSame([['cpu load=1i 1']], $this->sentBatches());
        $this->assertTrue($writeApi->closed);
        $this->assertNotSame($writeApi, $manager->writeApi());
    }

    public function testAConsoleCommandSendsTheBufferWhenItFinishes(): void
    {
        $this->batching('main', responses: self::accepted());

        $this->app->get(InfluxDBManager::class)->writeApi()->write('cpu load=1i 1');

        $this->artisan('influxdb:connections')->assertExitCode(0);

        $this->assertSame([['cpu load=1i 1']], $this->sentBatches());
    }

    public function testARequestTerminatingLeavesTheBufferToTheFlushTimer(): void
    {
        $this->batching('main', responses: self::accepted());

        $writeApi = $this->app->get(InfluxDBManager::class)->writeApi();
        $writeApi->write('cpu load=1i 1');

        // The HTTP kernel terminates each request inside the request's coroutine.
        $this->app->get('events')->dispatch(new Terminating);

        $this->assertSame([], $this->history);
        $this->assertSame(1, $writeApi->getPendingPoints());
    }

    /**
     * Turn batching on for a configured connection, over a transport that answers with the given responses.
     *
     * @param array<string, mixed> $options the `write` options, over batching
     * @param list<Response> $responses
     */
    private function batching(string $connection, array $options = [], array $responses = []): void
    {
        $this->app->get('config')->set("influxdb.connections.{$connection}.write", $options + ['writeType' => WriteType::BATCHING]);
        $this->app->get('config')->set("influxdb.connections.{$connection}.logFile", '/dev/null');
        $this->app->get('config')->set("influxdb.connections.{$connection}.httpClient", $this->transport($responses));
    }
}
