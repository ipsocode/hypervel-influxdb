<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Integration;

use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Support\Facades\DB;
use InfluxDB2\Model\WritePrecision;
use InfluxDB2\Point;
use InfluxDB2\WriteType;
use Ipsocode\InfluxDB\InfluxDBManager;
use Ipsocode\InfluxDB\InfluxQL\QueryException;
use Ipsocode\InfluxDB\InfluxQL\V1Connection;
use Ipsocode\InfluxDB\InfluxQL\Version;
use Ipsocode\InfluxDB\Write\BatchingWriter;
use Swoole\Coroutine;

/**
 * The package against a real InfluxDB 1.x: `INFLUXDB_V1_URL` names the server.
 *
 * @see src/docs/influxql.md
 */
class InfluxDBV1Test extends IntegrationTestCase
{
    /**
     * 2023-11-14T22:13:20.123456789Z, in nanoseconds.
     */
    private const int NANOSECONDS = 1_700_000_000_123_456_789;

    public function testTheServerNamesAVersionOneVersionOnPing(): void
    {
        $connection = $this->manager()->influxql('integration');

        $this->assertInstanceOf(V1Connection::class, $connection);
        $this->assertSame(Version::V1, $connection->getVersion());
        $this->assertMatchesRegularExpression('/^v?1\.\d+\.\d+/', (string) $connection->getServerVersion());
    }

    public function testPointsReadBackTheSameThroughTheBuilderAndTheDatabaseDriver(): void
    {
        $this->manager()->writeApi('integration')->write(
            Point::measurement('cpu')
                ->addTag('host', 'a')
                ->addTag('region', 'eu')
                ->addField('load', 1.5)
                ->addField('count', 3)
                ->addField('up', true)
                ->addField('note', 'hello world')
                ->time(1_700_000_000, WritePrecision::S),
            WritePrecision::S,
        );

        $expected = [
            'time' => 1_700_000_000,
            'count' => 3,
            'host' => 'a',
            'load' => 1.5,
            'note' => 'hello world',
            'region' => 'eu',
            'up' => true,
        ];

        $rows = $this->manager()->table('cpu', 'integration')->get();
        $driverRows = DB::connection('influxql')->table('cpu')->get();

        $this->assertCount(1, $rows);
        $this->assertCount(1, $driverRows);
        $this->assertSame('2023-11-14T22:13:20Z', $rows[0]->time);
        $this->assertEquals($expected, $this->withoutTime($rows[0], $expected));
        $this->assertSame($rows[0]->time, $driverRows[0]->time);
        $this->assertEquals($expected, $this->withoutTime($driverRows[0], $expected));
        $this->assertIsFloat($rows[0]->load);
        $this->assertIsInt($rows[0]->count);
        $this->assertIsBool($rows[0]->up);
    }

    public function testTimestampsAreReadAtTheConfiguredPrecision(): void
    {
        $this->manager()->writeApi('integration')->writeRaw('mem used=1i ' . self::NANOSECONDS, WritePrecision::NS);

        foreach (['ns' => self::NANOSECONDS, 'u' => 1_700_000_000_123_456, 'ms' => 1_700_000_000_123, 's' => 1_700_000_000] as $epoch => $time) {
            $this->app->get('config')->set('influxdb.connections.integration.influxql.epoch', $epoch);
            $this->manager()->disconnect('integration');

            $this->assertSame($time, $this->manager()->table('mem', 'integration')->first()->time, "epoch {$epoch}");
        }
    }

    public function testTimestampsAreWrittenAtEachPrecision(): void
    {
        $writeApi = $this->manager()->writeApi('integration');

        $writeApi->writeRaw('prec v=1i 1700000000123456789', WritePrecision::NS);
        $writeApi->writeRaw('prec v=2i 1700000001123456', WritePrecision::US);
        $writeApi->writeRaw('prec v=3i 1700000002123', WritePrecision::MS);
        $writeApi->writeRaw('prec v=4i 1700000003', WritePrecision::S);

        $this->app->get('config')->set('influxdb.connections.integration.influxql.epoch', 'ns');
        $this->manager()->disconnect('integration');

        $this->assertEquals(
            [
                [1_700_000_000_123_456_789, 1],
                [1_700_000_001_123_456_000, 2],
                [1_700_000_002_123_000_000, 3],
                [1_700_000_003_000_000_000, 4],
            ],
            array_map(
                static fn (object $row): array => [$row->time, $row->v],
                $this->manager()->table('prec', 'integration')->orderBy('time')->get()->all(),
            ),
        );
    }

    public function testABatchingConnectionsPointsArriveOnceItFlushesOnItsInterval(): void
    {
        $writeApi = $this->manager()->writeApi('integration-batch');

        $this->assertInstanceOf(BatchingWriter::class, $writeApi);

        $writeApi->write('batch value=1i 1700000000000000000');

        $this->eventually(fn (): bool => $this->manager()->table('batch', 'integration')->count() === 1);

        $this->assertSame(0, $writeApi->getPendingPoints());
    }

    public function testABatchingConnectionsPointsArriveOnceItDrainsWhenTheWorkerExits(): void
    {
        $this->app->get('config')->set('influxdb.connections.integration-batch.write.flushInterval', 3600);

        $writeApi = $this->manager()->writeApi('integration-batch');

        $writeApi->write('drain value=1i 1700000000000000000');

        $this->assertSame(0, $this->manager()->table('drain', 'integration')->count());

        CoordinatorManager::until(Constants::WORKER_EXIT)->resume();

        $this->eventually(fn (): bool => $this->manager()->table('drain', 'integration')->count() === 1);
    }

    public function testDeleteRemovesOnlyThePointsItsWhereMatches(): void
    {
        $this->manager()->writeApi('integration')->writeRaw(implode("\n", [
            'disk,host=a used=1i 1700000000000000000',
            'disk,host=a used=2i 1700000001000000000',
            'disk,host=b used=3i 1700000002000000000',
        ]), WritePrecision::NS);

        $this->assertTrue($this->manager()->table('disk', 'integration')->where('host', 'a')->delete());

        $this->assertSame([3], $this->manager()->table('disk', 'integration')->pluck('used')->all());
    }

    public function testSelectIntoWritesWhatTheServerComputes(): void
    {
        $this->manager()->writeApi('integration')->writeRaw(implode("\n", [
            'raw v=1i 1700000000000000000',
            'raw v=2i 1700000001000000000',
        ]), WritePrecision::NS);

        $this->manager()->table('raw', 'integration')->selectRaw('sum("v") as "total"')->into('summary')->get();

        $this->assertSame(3, $this->manager()->table('summary', 'integration')->first()->total);
    }

    public function testSlimitAndSoffsetReturnTheSeriesTheServerPicks(): void
    {
        $this->manager()->writeApi('integration')->writeRaw(implode("\n", [
            'net,host=a v=1i 1700000000000000000',
            'net,host=b v=2i 1700000000000000000',
            'net,host=c v=3i 1700000000000000000',
        ]), WritePrecision::NS);

        $query = fn () => $this->manager()->table('net', 'integration')->select('v')->groupBy('host');

        $this->assertSame(['a', 'b', 'c'], $query()->get()->pluck('host')->sort()->values()->all());
        $this->assertCount(1, $query()->slimit(1)->get());
        $this->assertSame(['c'], $query()->slimit(1)->soffset(2)->get()->pluck('host')->all());
    }

    public function testLimitAndOffsetApplyToEachSeriesOfAGroupedQuery(): void
    {
        $this->writeHosts();

        $query = fn () => $this->manager()->table('load', 'integration')->select('v')->groupBy('host');

        $this->assertSame(
            [['a', 1], ['a', 2], ['b', 11], ['b', 12], ['c', 21], ['c', 22]],
            $this->hostValues($query()->limit(2)->get()),
        );
        $this->assertSame([['a', 3], ['b', 13], ['c', 23]], $this->hostValues($query()->limit(2)->offset(2)->get()));
    }

    public function testChunkPagesAGroupedQueryToTheEndOnBothStacks(): void
    {
        $this->writeHosts();

        $builder = fn () => $this->manager()->table('load', 'integration')->select('v')->groupBy('host');
        $driver = fn () => DB::connection('influxql')->table('load')->select('v')->groupBy('host');

        foreach ([$builder, $driver] as $query) {
            $seen = [];
            $pages = 0;

            $query()->chunk(2, function ($rows) use (&$seen, &$pages): void {
                ++$pages;
                array_push($seen, ...$this->hostValues($rows));
            });

            sort($seen);

            $this->assertSame(2, $pages);
            $this->assertSame([['a', 1], ['a', 2], ['a', 3], ['b', 11], ['b', 12], ['b', 13], ['c', 21], ['c', 22], ['c', 23]], $seen);
        }
    }

    public function testAnIntegerComparedWithTimeIsNanosecondsUnlessItCarriesAUnit(): void
    {
        $writeApi = $this->manager()->writeApi('integration');

        $writeApi->writeRaw('clock v=1i ' . self::NANOSECONDS . "\nclock v=2i " . (self::NANOSECONDS + 1_000_000_000), WritePrecision::NS);

        $values = fn (string $where) => $this->manager()->table('clock', 'integration')->whereRaw($where)->orderBy('time')->pluck('v')->all();

        $this->assertSame([2], $values('time > ' . self::NANOSECONDS));
        $this->assertSame([1, 2], $values('time >= ' . self::NANOSECONDS));
        $this->assertSame([1, 2], $values('time > 1700000000123ms'));
        $this->assertSame([2], $values('time > 1700000000124ms'));
        $this->assertSame([2], $values('time > 1700000000124000u'));
        $this->assertSame([1, 2], $values('time > 1700000000124'));
    }

    public function testCursorPaginateTurnsAnEpochTimeBackIntoAnInstant(): void
    {
        $this->manager()->writeApi('integration')->writeRaw(implode("\n", array_map(
            static fn (int $i): string => 'pages v=' . $i . 'i ' . (1_700_000_000_000 + $i * 1_000),
            range(1, 5),
        )), WritePrecision::MS);

        foreach (['ms', 's', 'u'] as $epoch) {
            $this->app->get('config')->set('influxdb.connections.integration.influxql.epoch', $epoch);
            $this->manager()->disconnect('integration');
            DB::purge('influxql');

            $values = [];
            $cursor = null;

            do {
                $page = DB::connection('influxql')->table('pages')->orderBy('time')->cursorPaginate(2, ['*'], 'cursor', $cursor);

                array_push($values, ...$page->getCollection()->pluck('v')->all());

                $cursor = $page->nextCursor();
            } while ($cursor !== null);

            $this->assertSame([1, 2, 3, 4, 5], $values, "epoch {$epoch}");
        }
    }

    public function testASynchronousWriteIsRetriedAfterTheServerDropsTheConnection(): void
    {
        $proxy = $this->proxy(drop: 1);

        try {
            $this->app->get('config')->set('influxdb.connections.proxied', $this->proxiedConfig($proxy, [
                'write' => ['writeType' => WriteType::SYNCHRONOUS, 'maxRetries' => 3, 'retryInterval' => 50, 'maxRetryDelay' => 100],
            ]));

            $this->manager()->writeApi('proxied')->writeRaw('retried v=1i 1700000000000000000', WritePrecision::NS);

            $this->assertSame(2, $proxy->connections);
            $this->assertSame(1, $proxy->forwarded);
            $this->assertSame([1], $this->manager()->table('retried', 'integration')->pluck('v')->all());
        } finally {
            $proxy->stop();
        }
    }

    public function testASynchronousWriteIsRetriedAfterTheServerRefusesTheConnection(): void
    {
        $proxy = $this->proxy();
        $port = $proxy->start();
        $proxy->stop();

        Coroutine::create(function () use ($proxy, $port): void {
            usleep(300_000);

            $proxy->start($port);
        });

        try {
            $this->app->get('config')->set('influxdb.connections.proxied', $this->proxiedConfig($proxy, [
                'write' => ['writeType' => WriteType::SYNCHRONOUS, 'maxRetries' => 5, 'retryInterval' => 200, 'maxRetryDelay' => 400],
            ], $port));

            $this->manager()->writeApi('proxied')->writeRaw('refused v=1i 1700000000000000000', WritePrecision::NS);

            $this->assertSame([1], $this->manager()->table('refused', 'integration')->pluck('v')->all());
        } finally {
            $proxy->stop();
        }
    }

    public function testABatchIsRetriedAfterTheServerDropsTheConnection(): void
    {
        $proxy = $this->proxy(drop: 1);

        try {
            $this->app->get('config')->set('influxdb.connections.proxied', $this->proxiedConfig($proxy, [
                'write' => ['writeType' => WriteType::BATCHING, 'flushInterval' => 0.1, 'maxRetries' => 3, 'retryInterval' => 50, 'maxRetryDelay' => 100],
            ]));

            $this->manager()->writeApi('proxied')->write('batched v=1i 1700000000000000000');

            $this->eventually(fn (): bool => $this->manager()->table('batched', 'integration')->count() === 1);

            $this->assertSame(2, $proxy->connections);
        } finally {
            $proxy->stop();
        }
    }

    public function testABatchIsRetriedAfterTheServerRefusesTheConnection(): void
    {
        $proxy = $this->proxy();
        $port = $proxy->start();
        $proxy->stop();

        Coroutine::create(function () use ($proxy, $port): void {
            usleep(300_000);

            $proxy->start($port);
        });

        try {
            $this->app->get('config')->set('influxdb.connections.proxied', $this->proxiedConfig($proxy, [
                'write' => ['writeType' => WriteType::BATCHING, 'flushInterval' => 0.1, 'maxRetries' => 5, 'retryInterval' => 200, 'maxRetryDelay' => 400],
            ], $port));

            $this->manager()->writeApi('proxied')->write('refusedbatch v=1i 1700000000000000000');

            $this->eventually(fn (): bool => $this->manager()->table('refusedbatch', 'integration')->count() === 1);

            $this->assertSame([1], $this->manager()->table('refusedbatch', 'integration')->pluck('v')->all());
        } finally {
            $proxy->stop();
        }
    }

    public function testAStatementTheServerRejectsRaisesAQueryExceptionWithItsMessage(): void
    {
        try {
            $this->manager()->influxql('integration')->select('SELEC * FROM cpu');

            $this->fail('The server accepted a statement that does not parse.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('error parsing query', $exception->getError());
            $this->assertSame('SELEC * FROM cpu', $exception->getSql());
        }
    }

    /**
     * Nine points on three hosts, three each, a second apart: v is 1 to 3 on a, 11 to 13 on b and 21 to 23 on c.
     */
    private function writeHosts(): void
    {
        $lines = [];

        foreach (['a' => 0, 'b' => 10, 'c' => 20] as $host => $base) {
            foreach ([1, 2, 3] as $i) {
                $lines[] = "load,host={$host} v=" . ($base + $i) . 'i ' . (1_700_000_000 + $i);
            }
        }

        $this->manager()->writeApi('integration')->writeRaw(implode("\n", $lines), WritePrecision::S);
    }

    /**
     * @param iterable<object> $rows
     * @return list<array{string, int}>
     */
    private function hostValues(iterable $rows): array
    {
        $pairs = [];

        foreach ($rows as $row) {
            $pairs[] = [$row->host, $row->v];
        }

        usort($pairs, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return $pairs;
    }

    private function manager(): InfluxDBManager
    {
        return $this->app->get(InfluxDBManager::class);
    }

    /**
     * The row as an array, keyed and ordered as the expectation is, with its time as the expectation has it.
     *
     * @param array<string, mixed> $expected
     * @return array<string, mixed>
     */
    private function withoutTime(object $row, array $expected): array
    {
        $values = (array) $row;
        $values['time'] = $expected['time'];

        ksort($values);

        return $values;
    }
}
