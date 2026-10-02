<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature\Write;

use GuzzleHttp\Psr7\Response;
use Hypervel\Support\Facades\Exceptions;
use InfluxDB2\WriteType;
use InvalidArgumentException;
use Ipsocode\InfluxDB\Facades\InfluxDB;
use Ipsocode\InfluxDB\InfluxDBManager;
use Ipsocode\InfluxDB\Tests\Concerns\MocksWrites;
use Ipsocode\InfluxDB\Tests\TestCase;
use Ipsocode\InfluxDB\Write\BatchingWriter;
use Ipsocode\InfluxDB\Write\FailoverException;
use Ipsocode\InfluxDB\Write\FailoverOptions;
use Ipsocode\InfluxDB\Write\FailoverWriter;

/**
 * Falling back to other connections as an application turns it on: through a connection's `write` block,
 * or the write options given to `writeApi()`.
 */
class FailoverWritesTest extends TestCase
{
    use MocksWrites;

    public function testWriteApiIsAFailoverWriterWhenTheConnectionNamesAFallback(): void
    {
        $this->fallingBack('main', 'analytics');

        $manager = $this->app->get(InfluxDBManager::class);
        $writeApi = $manager->writeApi();

        $this->assertInstanceOf(FailoverWriter::class, $writeApi);
        $this->assertSame(['analytics'], $writeApi->getFailover()->getFallbacks());
        $this->assertSame(FailoverOptions::DEFAULT_COOLDOWN, $writeApi->getFailover()->getOptions()->cooldown);
        $this->assertSame($writeApi, $manager->writeApi('main'));
        $this->assertNotInstanceOf(FailoverWriter::class, $manager->writeApi('analytics'));
    }

    public function testTheCooldownsAreSharedByEveryWriterOfTheWorker(): void
    {
        $this->fallingBack('main', 'analytics');

        $manager = $this->app->get(InfluxDBManager::class);

        $this->assertSame($manager->availability(), $manager->writeApi()->getFailover()->getAvailability());
        $this->assertSame($manager->availability(), InfluxDB::availability());
        $this->assertTrue(InfluxDB::availability()->isAvailable('main'));
    }

    public function testFallbackMayBeTheCommaSeparatedNamesEnvReads(): void
    {
        $this->app->get('config')->set('influxdb.connections.archive', ['url' => 'http://archive.localhost:8086', 'token' => 'archive-token', 'bucket' => 'archive-bucket', 'org' => 'archive-org']);
        $this->fallingBack('main', 'analytics, archive', ['cooldown' => '5']);

        $failover = $this->app->get(InfluxDBManager::class)->writeApi()->getFailover();

        $this->assertSame(['analytics', 'archive'], $failover->getFallbacks());
        $this->assertSame(5.0, $failover->getOptions()->cooldown);
    }

    public function testASynchronousWriteFallsBackToTheConnectionNamed(): void
    {
        $reported = Exceptions::fake();
        $this->fallingBack('main', 'analytics', responses: [new Response(503, [], 'unavailable')]);
        $this->answering('analytics', self::accepted());

        InfluxDB::writeApi()->write('cpu load=1i 1');

        $this->assertSame([
            'localhost /api/v2/write?org=main-org&bucket=main-bucket&precision=ns',
            'analytics.localhost /api/v2/write?org=analytics-org&bucket=analytics-bucket&precision=ns',
        ], $this->sentThrough());
        $this->assertFalse(InfluxDB::availability()->isAvailable('main'));
        $reported->assertReported(FailoverException::class);
    }

    public function testABatchingConnectionFallsBackToo(): void
    {
        Exceptions::fake();
        $this->fallingBack('main', 'analytics', ['writeType' => WriteType::BATCHING], [new Response(503, [], 'unavailable')]);
        $this->answering('analytics', self::accepted());

        $manager = $this->app->get(InfluxDBManager::class);
        $writeApi = $manager->writeApi();

        $this->assertInstanceOf(BatchingWriter::class, $writeApi);
        $this->assertSame(['analytics'], $writeApi->getFailover()->getFallbacks());

        $writeApi->write('cpu load=1i 1');
        $manager->flush();

        $this->assertSame([
            'localhost /api/v2/write?org=main-org&bucket=main-bucket&precision=ns',
            'analytics.localhost /api/v2/write?org=analytics-org&bucket=analytics-bucket&precision=ns',
        ], $this->sentThrough());
        $this->assertSame(0, $writeApi->getPendingPoints());
    }

    public function testWriteOptionsGivenToWriteApiMayNameAFallback(): void
    {
        $writeApi = $this->app->get(InfluxDBManager::class)->writeApi('analytics', ['fallback' => 'main', 'cooldown' => 0]);

        $this->assertInstanceOf(FailoverWriter::class, $writeApi);
        $this->assertSame(['main'], $writeApi->getFailover()->getFallbacks());
        $this->assertSame(0.0, $writeApi->getFailover()->getOptions()->cooldown);
    }

    public function testAFallbackThatIsNotConfiguredFailsWhenTheWriteApiIsFirstResolved(): void
    {
        $this->fallingBack('main', 'missing');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('InfluxDB connection [main] has an invalid write.fallback [missing]; expected the name of a configured connection: InfluxDB connection [missing] is not configured.');

        $this->app->get(InfluxDBManager::class)->writeApi();
    }

    public function testAFallbackMissingARequiredKeyFailsTheSameWay(): void
    {
        $this->app->get('config')->set('influxdb.connections.broken', ['url' => 'http://broken.localhost:8086']);
        $this->fallingBack('main', 'broken');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('InfluxDB connection [main] has an invalid write.fallback [broken]; expected the name of a configured connection: InfluxDB connection [broken] is missing the required [token] config key.');

        $this->app->get(InfluxDBManager::class)->writeApi();
    }

    public function testAConnectionCannotFallBackToItself(): void
    {
        $this->fallingBack('main', 'main');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('InfluxDB connection [main] has an invalid write.fallback [main]; expected the name of another connection.');

        $this->app->get(InfluxDBManager::class)->writeApi();
    }

    /**
     * Name the fallback of a configured connection, with the client's retries off, over a transport answering with the given responses.
     *
     * @param array<string, mixed> $options the `write` options, over the fallback
     * @param list<Response> $responses
     */
    private function fallingBack(string $connection, string $fallback, array $options = [], array $responses = []): void
    {
        $this->app->get('config')->set("influxdb.connections.{$connection}.write", $options + ['fallback' => $fallback, 'maxRetries' => 0]);

        $this->answering($connection, $responses);
    }

    /**
     * Give a configured connection a transport answering with the given responses.
     *
     * @param list<Response> $responses
     */
    private function answering(string $connection, array $responses): void
    {
        $this->app->get('config')->set("influxdb.connections.{$connection}.logFile", '/dev/null');
        $this->app->get('config')->set("influxdb.connections.{$connection}.httpClient", $this->transport($responses));
    }
}
