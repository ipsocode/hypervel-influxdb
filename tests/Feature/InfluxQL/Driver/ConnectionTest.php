<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature\InfluxQL\Driver;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Hypervel\Database\Connection as DatabaseConnection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Query\Expression;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Database\QueryException;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\DB;
use InfluxDB2\ApiException;
use InvalidArgumentException;
use Ipsocode\InfluxDB\InfluxDBManager;
use Ipsocode\InfluxDB\InfluxQL\Driver\Builder;
use Ipsocode\InfluxDB\InfluxQL\Driver\Connection;
use Ipsocode\InfluxDB\InfluxQL\Driver\Grammar;
use Ipsocode\InfluxDB\InfluxQL\Regex;
use Ipsocode\InfluxDB\InfluxQL\Series;
use Ipsocode\InfluxDB\InfluxQL\V1Connection;
use Ipsocode\InfluxDB\InfluxQL\V2Connection;
use Ipsocode\InfluxDB\InfluxQL\V3Connection;
use Ipsocode\InfluxDB\InfluxQL\Version;
use Ipsocode\InfluxDB\Tests\Concerns\MocksInfluxQLDriver;
use Ipsocode\InfluxDB\Tests\TestCase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use UnitEnum;

/**
 * InfluxQL through the `influxql` database driver and Hypervel's database manager.
 *
 * The database connections run on InfluxDB connections whose transport is a
 * Guzzle mock, as MocksInfluxQLDriver configures them: `influxql` on an
 * InfluxDB 1.x connection, and `influxql-v1`, `influxql-v2` and `influxql-v3`
 * on one of each version. Each test queues the answers to its /query
 * requests, the transport answers /ping itself, and every request is
 * recorded.
 */
class ConnectionTest extends TestCase
{
    use MocksInfluxQLDriver;

    public function testTheInfluxqlDriverMakesAConnectionOnTheInfluxdbConnectionItNames(): void
    {
        $connection = DB::connection('influxql');

        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertSame('influxql', $connection->getName());
        $this->assertSame('influxql', $connection->getDriverName());
        $this->assertSame('telegraf', $connection->getDatabaseName());
        $this->assertSame('v1', $connection->getConfig('connection'));
        $this->assertSame('v1.localhost', $connection->getConfig('host'));
        $this->assertSame(8086, $connection->getConfig('port'));
        $this->assertSame(Version::V1, $connection->getVersion());
        $this->assertInstanceOf(Grammar::class, $connection->getQueryGrammar());
        $this->assertSame(Processor::class, $connection->getPostProcessor()::class);
        $this->assertInstanceOf(V1Connection::class, $connection->getInfluxQL());
        $this->assertSame('autogen', $connection->getInfluxQL()->getRetentionPolicy());
        $this->assertInstanceOf(Builder::class, $connection->query());
        $this->assertInstanceOf(Builder::class, $connection->table('cpu'));
        $this->assertSame([], $this->history);
    }

    /**
     * @param class-string $class
     * @param array<string, string> $parameters
     */
    #[DataProvider('versions')]
    public function testEachVersionIsAddressedAsItsInfluxdbConnectionIs(string $name, Version $version, string $class, string $database, array $parameters): void
    {
        $this->responses->append(self::emptyResponse());

        $connection = DB::connection($name);
        $connection->table('cpu')->get();

        $this->assertSame($version, $connection->getVersion());
        $this->assertInstanceOf($class, $connection->getInfluxQL());
        $this->assertSame($database, $connection->getDatabaseName());
        $this->assertSame($parameters, $this->lastQueryParameters());
    }

    /**
     * @return array<string, array{string, Version, class-string, string, array<string, string>}>
     */
    public static function versions(): array
    {
        return [
            'InfluxDB 1.x' => ['influxql-v1', Version::V1, V1Connection::class, 'telegraf', ['db' => 'telegraf', 'rp' => 'autogen']],
            'InfluxDB 2.x' => ['influxql-v2', Version::V2, V2Connection::class, 'telegraf', ['db' => 'telegraf', 'rp' => 'autogen']],
            'InfluxDB 3' => ['influxql-v3', Version::V3, V3Connection::class, 'telegraf/autogen', ['db' => 'telegraf/autogen']],
        ];
    }

    public function testAConnectionThatNamesNoInfluxdbConnectionRunsOnTheDefaultOne(): void
    {
        $this->app->get('config')->set('influxdb.default', 'v3');
        $this->app->get('config')->set('database.connections.metrics', ['driver' => 'influxql']);

        $connection = DB::connection('metrics');

        $this->assertSame('v3', $connection->getConfig('connection'));
        $this->assertSame(Version::V3, $connection->getVersion());
    }

    public function testTheDatabaseIsTheOneTheStatementsAreAddressedTo(): void
    {
        $this->app->get('config')->set('database.connections.influxql.database', 'other');
        $this->responses->append(self::emptyResponse());

        $connection = DB::connection('influxql');

        $this->assertSame('telegraf', $connection->getDatabaseName());

        $connection->setDatabaseName('other')->select('select * from "cpu"');

        $this->assertSame('telegraf', $this->lastQueryParameters()['db'], 'The InfluxDB connection addresses the statements.');
    }

    public function testAnInfluxdbConnectionThatIsNotConfiguredIsRefused(): void
    {
        $this->app->get('config')->set('database.connections.influxql.connection', 'missing');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('InfluxDB connection [missing] is not configured.');

        DB::connection('influxql');
    }

    public function testAnInfluxdbConnectionWithAnUnsupportedVersionIsRefused(): void
    {
        $this->app->get('config')->set('influxdb.connections.v1.version', 'v9');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('InfluxDB connection [v1] has an unsupported version [v9]; expected one of v1, v2, v3.');

        Connection::fromConfig($this->app->get(InfluxDBManager::class), ['name' => 'influxql', 'connection' => 'v1']);
    }

    public function testADriverNameIsReportedWithoutAConfiguredOne(): void
    {
        $this->assertSame('influxql', (new Connection(null))->getDriverName());
    }

    public function testAQueryIsOnePostOfItsInfluxqlWithTheValuesEmbedded(): void
    {
        $this->responses->append(self::seriesResponse([
            self::series('cpu', ['time', 'host', 'usage_user'], [['2024-01-01T00:00:00Z', "it's", 0.64]]),
        ]));

        $query = DB::connection('influxql')
            ->table('cpu')
            ->select('host', 'usage_user')
            ->where('host', "it's")
            ->where('ok', true)
            ->where('host', '=~', new Regex('^web'))
            ->where('time', '>=', new DateTimeImmutable('2024-01-01 02:00:00', new DateTimeZone('+02:00')));

        $rows = $query->get();

        $this->assertInstanceOf(Collection::class, $rows);
        $this->assertEquals([(object) ['time' => '2024-01-01T00:00:00Z', 'host' => "it's", 'usage_user' => 0.64]], $rows->all());

        $request = $this->lastRequest();

        $this->assertSame(['GET /ping', 'POST /query'], $this->requestLines());
        $this->assertSame('v1.localhost', $request->getUri()->getHost());
        $this->assertSame('Token v1-token', $request->getHeaderLine('Authorization'));
        $this->assertSame(
            'select "host", "usage_user" from "cpu" where "host" = \'it\\\'s\' and "ok" = true and "host" =~ /^web/ and "time" >= \'2024-01-01T00:00:00.000000Z\'',
            $this->lastStatement(),
        );
        $this->assertSame($query->toRawSql(), $this->lastStatement());
    }

    public function testRawStatementsRunOnTheConnection(): void
    {
        $this->responses->append(
            self::seriesResponse([self::series('cpu', ['time', 'host'], [['2024-01-01T00:00:00Z', 'web1'], ['2024-01-01T00:00:10Z', 'web2']])]),
            self::seriesResponse([self::series('cpu', ['time', 'host'], [['2024-01-01T00:00:00Z', 'web1']])]),
            self::emptyResponse(),
        );

        $connection = DB::connection('influxql');

        $this->assertEquals(
            [(object) ['time' => '2024-01-01T00:00:00Z', 'host' => 'web1'], (object) ['time' => '2024-01-01T00:00:10Z', 'host' => 'web2']],
            $connection->select('select "host" from "cpu" where time > ?', [new DateTimeImmutable('2024-01-01T00:00:00Z')]),
        );
        $this->assertEquals((object) ['time' => '2024-01-01T00:00:00Z', 'host' => 'web1'], $connection->selectOne('select "host" from "cpu" where "ok" = ?', [false]));
        $this->assertTrue($connection->unprepared('drop measurement "cpu"'));
        $this->assertSame([
            'select "host" from "cpu" where time > \'2024-01-01T00:00:00.000000Z\'',
            'select "host" from "cpu" where "ok" = false',
            'drop measurement "cpu"',
        ], $this->statements());
    }

    public function testTheTagsOfEachSeriesAreHydratedIntoItsRows(): void
    {
        $this->responses->append(self::seriesResponse([
            self::series('cpu', ['time', 'mean'], [['2024-01-01T00:00:00Z', 0.5]], ['host' => 'web1']),
            self::series('cpu', ['time', 'mean'], [['2024-01-01T00:00:00Z', 0.25]], ['host' => 'web2']),
        ]));

        $rows = DB::connection('influxql')->table('cpu')->selectRaw('mean("usage_user") as "mean"')->groupBy('host')->get();

        $this->assertEquals([
            (object) ['time' => '2024-01-01T00:00:00Z', 'mean' => 0.5, 'host' => 'web1'],
            (object) ['time' => '2024-01-01T00:00:00Z', 'mean' => 0.25, 'host' => 'web2'],
        ], $rows->all());
    }

    public function testSeriesComeBackAsTheServerGroupedThem(): void
    {
        $this->responses->append(
            self::seriesResponse([
                self::series('cpu', ['time', 'mean'], [['2024-01-01T00:00:00Z', 0.5]], ['host' => 'web1']),
                self::series('cpu', ['time', 'mean'], [['2024-01-01T00:00:00Z', 0.25]], ['host' => 'web2']),
            ]),
            self::noResultsResponse(),
        );

        $connection = DB::connection('influxql');
        $series = $connection->series('select mean("usage_user") from "cpu" where "host" =~ ? group by "host"', [new Regex('^web')]);

        $this->assertCount(2, $series);
        $this->assertContainsOnlyInstancesOf(Series::class, $series);
        $this->assertSame(['host' => 'web2'], $series[1]->tags);
        $this->assertSame([['2024-01-01T00:00:00Z', 0.25]], $series[1]->values);
        $this->assertSame([], $connection->series('select * from "nothing"'));
        $this->assertSame([
            'select mean("usage_user") from "cpu" where "host" =~ /^web/ group by "host"',
            'select * from "nothing"',
        ], $this->statements());
    }

    public function testAnEmptyResultIsAnEmptyCollection(): void
    {
        $this->responses->append(self::emptyResponse());

        $this->assertTrue(DB::connection('influxql')->table('cpu')->get()->isEmpty());
    }

    public function testAnEloquentModelReadsThroughTheConnection(): void
    {
        $this->responses->append(self::seriesResponse([
            self::series('cpu', ['time', 'host', 'usage_user'], [['2024-01-01T00:00:00Z', 'web1', 0.64]]),
        ]));

        $cpu = self::model()->newQuery()->where('host', 'web1')->first();

        $this->assertInstanceOf(Model::class, $cpu);
        $this->assertSame('web1', $cpu->getAttribute('host'));
        $this->assertSame(0.64, $cpu->getAttribute('usage_user'));
        $this->assertSame('select * from "cpu" where "host" = \'web1\' limit 1', $this->lastStatement());
    }

    public function testAStatementTheServerRefusesIsAQueryExceptionCarryingTheStatementOnce(): void
    {
        $this->responses->append(self::errorResponse('database not found: telegraf'));

        try {
            DB::connection('influxql')->table('nothere')->where('host', 'web1')->get();

            $this->fail('No exception was thrown.');
        } catch (QueryException $exception) {
            $this->assertInstanceOf(RuntimeException::class, $exception->getPrevious());
            $this->assertSame('database not found: telegraf', $exception->getPrevious()->getMessage());
            $this->assertSame('influxql', $exception->getConnectionName());
            $this->assertSame('select * from "nothere" where "host" = ?', $exception->getSql());
            $this->assertSame(['web1'], $exception->getBindings());
            $this->assertSame('select * from "nothere" where "host" = \'web1\'', $exception->getRawSql());
            $this->assertSame(
                'database not found: telegraf (Connection: influxql, Host: v1.localhost, Port: 8086, Database: telegraf, SQL: select * from "nothere" where "host" = web1)',
                $exception->getMessage(),
            );
        }
    }

    public function testATransportFailureIsAQueryExceptionOnTheUpstreamException(): void
    {
        $this->responses->append(new Response(400, ['Content-Type' => 'application/json'], '{"error":"error parsing query: found WHERE, expected identifier"}'));

        try {
            DB::connection('influxql')->select('select * from where');

            $this->fail('No exception was thrown.');
        } catch (QueryException $exception) {
            $this->assertInstanceOf(ApiException::class, $exception->getPrevious());
            $this->assertStringContainsString('error parsing query: found WHERE, expected identifier', $exception->getMessage());
            $this->assertStringNotContainsString('InfluxQL:', $exception->getMessage(), 'The statement is named once, by Hypervel.');
        }
    }

    public function testALostConnectionIsReestablishedAndTheStatementTriedOnceMore(): void
    {
        $this->responses->append(
            new ConnectException('Connection reset by peer', new Request('POST', '/query')),
            self::seriesResponse([self::series('cpu', ['time', 'host'], [['2024-01-01T00:00:00Z', 'web1']])]),
        );

        $connection = DB::connection('influxql');
        $influxql = $connection->getInfluxQL();

        $this->assertEquals([(object) ['time' => '2024-01-01T00:00:00Z', 'host' => 'web1']], $connection->table('cpu')->get()->all());
        $this->assertNotSame($influxql, $connection->getInfluxQL());
        $this->assertSame('telegraf', $connection->getDatabaseName());
        $this->assertSame(['GET /ping', 'POST /query', 'GET /ping', 'POST /query'], $this->requestLines(), 'The new InfluxQL connection asks for the version again.');
    }

    /**
     * @param Closure(DatabaseConnection): mixed $write
     */
    #[DataProvider('writes')]
    public function testEveryWriteIsRefusedBeforeAnythingIsSent(Closure $write, string $message): void
    {
        try {
            $write(DB::connection('influxql'));

            $this->fail('No exception was thrown.');
        } catch (LogicException $exception) {
            $this->assertSame($message, $exception->getMessage());
            $this->assertSame([], $this->history);
        }
    }

    /**
     * @return array<string, array{Closure(DatabaseConnection): mixed, string}>
     */
    public static function writes(): array
    {
        $compiled = 'InfluxQL has no INSERT, UPDATE, UPSERT or TRUNCATE; write points through InfluxDB::writeApi() instead.';
        $raw = 'InfluxQL has no INSERT or UPDATE; write points through InfluxDB::writeApi() instead.';

        return [
            'insert' => [static fn (DatabaseConnection $db): mixed => $db->table('cpu')->insert(['host' => 'web1']), $compiled],
            'insertGetId' => [static fn (DatabaseConnection $db): mixed => $db->table('cpu')->insertGetId(['host' => 'web1']), $compiled],
            'insertOrIgnore' => [static fn (DatabaseConnection $db): mixed => $db->table('cpu')->insertOrIgnore(['host' => 'web1']), $compiled],
            'upsert' => [static fn (DatabaseConnection $db): mixed => $db->table('cpu')->upsert([['host' => 'web1', 'usage_user' => 1]], ['host']), $compiled],
            'update' => [static fn (DatabaseConnection $db): mixed => $db->table('cpu')->where('host', 'web1')->update(['usage_user' => 1]), $compiled],
            'increment' => [static fn (DatabaseConnection $db): mixed => $db->table('cpu')->increment('usage_user'), $compiled],
            'truncate' => [static fn (DatabaseConnection $db): mixed => $db->table('cpu')->truncate(), $compiled],
            'a raw insert' => [static fn (DatabaseConnection $db): mixed => $db->insert('insert into "cpu" ("host") values (?)', ['web1']), $raw],
            'a raw update' => [static fn (DatabaseConnection $db): mixed => $db->update('update "cpu" set "usage_user" = ?', [1]), $raw],
            'an Eloquent create, on a model that is not a Measurement' => [static fn (): mixed => self::model()->newQuery()->create(['host' => 'web1']), $compiled],
        ];
    }

    public function testADeleteIsSentOnInfluxdb1(): void
    {
        $this->responses->append(self::emptyResponse(), self::emptyResponse());

        $connection = DB::connection('influxql');

        $this->assertSame(0, $connection->table('cpu')->where('host', 'web1')->delete(), 'InfluxQL reports no count.');
        $this->assertSame(0, $connection->delete('delete from "cpu" where time < ?', [new DateTimeImmutable('2024-01-01T00:00:00Z')]));
        $this->assertSame([
            'delete from "cpu" where "host" = \'web1\'',
            'delete from "cpu" where time < \'2024-01-01T00:00:00.000000Z\'',
        ], $this->statements());
    }

    public function testADeleteIsSentOnInfluxdb2WhenTheConnectionAddressesNoRetentionPolicy(): void
    {
        $this->app->get('config')->set('influxdb.connections.v2.bucket', 'telegraf');
        $this->responses->append(self::noResultsResponse());

        DB::connection('influxql-v2')->table('cpu')->where('host', 'web1')->delete();

        $this->assertSame(['delete from "cpu" where "host" = \'web1\''], $this->statements());
        $this->assertSame(['db' => 'telegraf'], $this->lastQueryParameters());
    }

    #[DataProvider('deletesAVersionDoesNotRun')]
    public function testADeleteIsRefusedWhereTheVersionCannotRunItHere(string $connection, string $message): void
    {
        try {
            DB::connection($connection)->table('cpu')->where('host', 'web1')->delete();

            $this->fail('No exception was thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($message, $exception->getMessage());
            $this->assertSame([], $this->history);
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function deletesAVersionDoesNotRun(): array
    {
        return [
            'InfluxDB 2.x, on a retention policy' => ['influxql-v2', "InfluxDB 2.x deletes only from the database's default retention policy, not from the connection's [autogen]; delete through the /api/v2/delete API instead."],
            'InfluxDB 3' => ['influxql-v3', 'InfluxDB 3 does not support DELETE; delete the table or the database instead.'],
        ];
    }

    /**
     * @param Closure(DatabaseConnection): mixed $transaction
     */
    #[DataProvider('transactions')]
    public function testTransactionsAreRefused(Closure $transaction): void
    {
        $connection = DB::connection('influxql');

        try {
            $transaction($connection);

            $this->fail('No exception was thrown.');
        } catch (LogicException $exception) {
            $this->assertSame('Database driver [influxql] does not support transactions.', $exception->getMessage());
        }

        $this->assertFalse($connection->inTransaction());
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertSame([], $this->history);
    }

    /**
     * @return array<string, array{Closure(DatabaseConnection): mixed}>
     */
    public static function transactions(): array
    {
        return [
            'transaction' => [static fn (DatabaseConnection $db): mixed => $db->transaction(static fn (): null => null)],
            'beginTransaction' => [static fn (DatabaseConnection $db): mixed => $db->beginTransaction()],
        ];
    }

    public function testPretendingSendsNothingAndLogsEachStatementAsItWouldBeSent(): void
    {
        $queries = DB::connection('influxql')->pretend(function (Connection $db): void {
            $this->assertSame([], $db->table('cpu')->where('host', 'web1')->get()->all());
            $this->assertTrue($db->statement('delete from "cpu" where "host" = ?', ['web2']));
            $this->assertSame([], $db->series('select * from "cpu"'));
        });

        $this->assertSame([
            'select * from "cpu" where "host" = \'web1\'',
            'delete from "cpu" where "host" = \'web2\'',
            'select * from "cpu"',
        ], array_column($queries, 'query'));
        $this->assertSame([], $this->history, 'Not even the version is asked for.');
    }

    public function testACursorYieldsTheRowsOfOneRequestOnlyOnceIteratedOver(): void
    {
        $this->responses->append(
            self::seriesResponse([self::series('cpu', ['time', 'host'], [['2024-01-01T00:00:00Z', 'web1'], ['2024-01-01T00:00:10Z', 'web2']])]),
            self::seriesResponse([self::series('mem', ['time', 'host'], [['2024-01-01T00:00:00Z', 'web3']])]),
        );

        $connection = DB::connection('influxql');
        $cursor = $connection->table('cpu')->select('host')->cursor();

        $this->assertSame([], $this->history);
        $this->assertSame(['web1', 'web2'], $cursor->pluck('host')->all());
        $this->assertEquals([(object) ['time' => '2024-01-01T00:00:00Z', 'host' => 'web3']], iterator_to_array($connection->cursor('select "host" from "mem"')));
        $this->assertSame(['select "host" from "cpu"', 'select "host" from "mem"'], $this->statements());
    }

    public function testTheServersVersionIsAskedForOnceAndKept(): void
    {
        $this->responses->append(self::emptyResponse());

        $connection = DB::connection('influxql');

        $this->assertSame('1.8.10', $connection->getServerVersion());
        $this->assertSame('1.8.10', $connection->getServerVersion());

        $connection->table('cpu')->get();

        $this->assertSame(['GET /ping', 'POST /query'], $this->requestLines());
    }

    public function testAServerThatNamesNoVersionReportsAnEmptyOne(): void
    {
        $this->pings['v1.localhost'] = null;

        $this->assertSame('', DB::connection('influxql')->getServerVersion());
    }

    /**
     * @param null|string $reported what the server names on /ping
     */
    #[DataProvider('versionsTheDriverDoesNotCompileFor')]
    public function testAServerOfAnotherVersionIsRefusedBeforeTheStatementIsSent(?string $reported, string $reports): void
    {
        $this->pings['v1.localhost'] = $reported;

        try {
            DB::connection('influxql')->table('cpu')->where('host', 'web1')->get();

            $this->fail('No exception was thrown.');
        } catch (QueryException $exception) {
            $this->assertInstanceOf(RuntimeException::class, $exception->getPrevious());
            $this->assertSame("The influxql driver compiles for InfluxDB 1.x on this connection (version v1), but the server reports {$reports}.", $exception->getPrevious()->getMessage());
            $this->assertSame('select * from "cpu" where "host" = ?', $exception->getSql());
            $this->assertSame(['GET /ping'], $this->requestLines());
        }
    }

    /**
     * @return array<string, array{?string, string}>
     */
    public static function versionsTheDriverDoesNotCompileFor(): array
    {
        return [
            'InfluxDB 2.x' => ['v2.7.12', 'version [v2.7.12]'],
            'InfluxDB 3' => ['3.11.5', 'version [3.11.5]'],
            'no version' => [null, 'no version'],
        ];
    }

    public function testAStatementWithAValueInfluxqlCannotWriteFailsBeforeTheVersionIsAskedFor(): void
    {
        try {
            DB::connection('influxql')->select('select * from "cpu" where "host" = ?', [['web1', 'web2']]);

            $this->fail('No exception was thrown.');
        } catch (QueryException $exception) {
            $this->assertInstanceOf(InvalidArgumentException::class, $exception->getPrevious());
            $this->assertSame('An array cannot be embedded in InfluxQL; use whereIn() or whereBetween().', $exception->getPrevious()->getMessage());
            $this->assertSame([], $this->history);
        }
    }

    public function testAServerThatCannotBeAskedForItsVersionIsAQueryExceptionCarryingTheStatement(): void
    {
        $this->pings['v1.localhost'] = new Response(503, [], 'unavailable');

        try {
            DB::connection('influxql')->select('select * from "cpu"');

            $this->fail('No exception was thrown.');
        } catch (QueryException $exception) {
            $this->assertInstanceOf(ApiException::class, $exception->getPrevious());
            $this->assertSame('select * from "cpu"', $exception->getSql());
            $this->assertSame(['GET /ping'], $this->requestLines());
        }
    }

    public function testTheServersHealthComesFromPing(): void
    {
        $connection = DB::connection('influxql');

        $this->assertTrue($connection->ping());

        $this->pings['v1.localhost'] = new Response(503, [], 'unavailable');

        $this->assertFalse($connection->ping());
        $this->assertSame(['GET /ping', 'GET /ping'], $this->requestLines());
    }

    public function testACancelledPingIsNotTakenForAnUnhealthyServer(): void
    {
        $this->pings['v1.localhost'] = new CanceledException('The heartbeat was canceled.');

        $this->expectException(CanceledException::class);

        DB::connection('influxql')->ping();
    }

    public function testADisconnectedConnectionLetsGoOfItsInfluxqlConnectionAndReconnectsForItsNextStatement(): void
    {
        $this->responses->append(self::emptyResponse());

        $connection = DB::connection('influxql');
        $influxql = $connection->getInfluxQL();

        $connection->disconnect();

        $this->assertTrue($connection->ping(), 'A connection with nothing open has nothing to fail.');
        $this->assertSame([], $this->history);

        $connection->table('cpu')->get();

        $this->assertNotSame($influxql, $reconnected = $connection->getInfluxQL());
        $this->assertSame($connection, DB::reconnect('influxql'));
        $this->assertNotSame($reconnected, $connection->getInfluxQL());
        $this->assertSame(['GET /ping', 'POST /query'], $this->requestLines());
    }

    public function testReconnectingTakesTheVersionAndRetentionPolicyTheConfigNowNames(): void
    {
        $connection = DB::connection('influxql');

        $this->app->get('config')->set('influxdb.connections.v1.version', 'v2');

        DB::reconnect('influxql');

        $this->assertSame(Version::V2, $connection->getVersion());
        $this->assertInstanceOf(V2Connection::class, $connection->getInfluxQL());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs("InfluxDB 2.x deletes only from the database's default retention policy, not from the connection's [autogen]; delete through the /api/v2/delete API instead.");

        $connection->table('cpu')->delete();
    }

    public function testValuesAreEscapedAsTheGrammarEmbedsThem(): void
    {
        $connection = DB::connection('influxql');

        $this->assertSame("'it\\'s'", $connection->escape("it's"));
        $this->assertSame('true', $connection->escape(true));
        $this->assertSame('42', $connection->escape(42));
        $this->assertSame('0.5', $connection->escape(0.5));
        $this->assertSame("'2024-01-01T00:00:00.000000Z'", $connection->escape(new DateTimeImmutable('2024-01-01 02:00:00', new DateTimeZone('+02:00'))));
        $this->assertSame('/^web/', $connection->escape(new Regex('^web')));
        $this->assertSame('now() - 1h', $connection->escape(new Expression('now() - 1h')));
    }

    public function testNullHasNoInfluxqlLiteral(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('InfluxQL has no null literal; a field or tag cannot be compared with null.');

        DB::connection('influxql')->escape(null);
    }

    public function testABinaryValueHasNoInfluxqlLiteral(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('InfluxQL has no binary literal.');

        DB::connection('influxql')->escape('bytes', true);
    }

    /**
     * A plain model on the `influxql` connection, whose writes reach the driver: a Measurement refuses its own first.
     */
    private static function model(): Model
    {
        return new class extends Model {
            protected UnitEnum|string|null $connection = 'influxql';

            protected ?string $table = 'cpu';

            public bool $timestamps = false;

            protected array $guarded = [];
        };
    }
}
