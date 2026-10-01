<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature\Sql;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Connection;
use Hypervel\Database\Query\JoinClause;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Database\QueryException;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\DB;
use InfluxDB2\ApiException;
use InvalidArgumentException;
use Ipsocode\InfluxDB\InfluxDBManager;
use Ipsocode\InfluxDB\Sql\SqlConnection;
use Ipsocode\InfluxDB\Sql\SqlGrammar;
use Ipsocode\InfluxDB\Tests\Fixtures\Cpu;
use Ipsocode\InfluxDB\Tests\TestCase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Swoole\Coroutine\CanceledException;

/**
 * The `influxdb` database driver, on an InfluxDB 3 connection whose transport is a Guzzle mock
 * given through the client's `httpClient` option; SqlGrammarTest covers the grammar alone.
 */
class SqlConnectionTest extends TestCase
{
    /**
     * The requests sent through the mocked transport, oldest first.
     *
     * @var list<array{request: RequestInterface, response: null|Response}>
     */
    protected array $history = [];

    /**
     * The mocked transport, which answers each request with the next response queued on it.
     */
    protected MockHandler $responses;

    #[DataProvider('versionsBefore3')]
    public function testAnInfluxdbConnectionOfAnotherVersionIsRefused(?string $version, string $named): void
    {
        $this->app->get('config')->set('influxdb.connections.lake.version', $version);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs("Database connection [influxdb] uses InfluxDB connection [lake], which is version {$named}; SQL needs an InfluxDB 3 connection (version v3).");

        DB::connection('influxdb');
    }

    /**
     * @return array<string, array{?string, string}>
     */
    public static function versionsBefore3(): array
    {
        return [
            'unset, which is v1' => [null, 'v1'],
            'v1' => ['v1', 'v1'],
            'v2' => ['v2', 'v2'],
        ];
    }

    public function testTheInfluxdbDriverMakesAnSqlConnectionOnTheInfluxdbConnectionItNames(): void
    {
        $connection = DB::connection('influxdb');

        $this->assertInstanceOf(SqlConnection::class, $connection);
        $this->assertSame('influxdb', $connection->getName());
        $this->assertSame('influxdb', $connection->getDriverName());
        $this->assertSame('telegraf/autogen', $connection->getDatabaseName());
        $this->assertSame('lake', $connection->getConfig('connection'));
        $this->assertSame('lake.localhost', $connection->getConfig('host'));
        $this->assertSame(8181, $connection->getConfig('port'));
        $this->assertInstanceOf(SqlGrammar::class, $connection->getQueryGrammar());
        $this->assertSame(Processor::class, $connection->getPostProcessor()::class);
        $this->assertSame('http://lake.localhost:8181', $connection->getApi()->options['url']);
        $this->assertSame([], $this->history);
    }

    public function testAConnectionThatNamesNoInfluxdbConnectionRunsOnTheDefaultOne(): void
    {
        $this->app->get('config')->set('influxdb.default', 'lake');
        $this->app->get('config')->set('database.connections.lakehouse', ['driver' => 'influxdb']);

        $connection = DB::connection('lakehouse');

        $this->assertSame('lake', $connection->getConfig('connection'));
        $this->assertSame('telegraf/autogen', $connection->getDatabaseName());
    }

    public function testTheDatabaseIsTheConnectionsOwnOrElseTheInfluxdbConnectionsBucket(): void
    {
        $this->app->get('config')->set('database.connections.metrics', ['driver' => 'influxdb', 'connection' => 'lake', 'database' => 'metrics']);
        $this->app->get('config')->set('database.connections.unset', ['driver' => 'influxdb', 'connection' => 'lake', 'database' => '']);
        $this->responses->append(self::rows([]));

        DB::connection('metrics')->table('cpu')->get();

        $this->assertSame('metrics', $this->lastQuery()['db']);
        $this->assertSame('metrics', DB::connection('metrics')->getConfig('database'));
        $this->assertSame('telegraf/autogen', DB::connection('unset')->getDatabaseName());
    }

    public function testStatementsRunAgainstTheConnectionsCurrentDatabase(): void
    {
        $this->responses->append(self::rows([]));

        DB::connection('influxdb')->setDatabaseName('metrics')->select('select 1');

        $this->assertSame('metrics', $this->lastQuery()['db']);
    }

    public function testAnInfluxdbConnectionThatIsNotConfiguredIsRefused(): void
    {
        $this->app->get('config')->set('database.connections.influxdb.connection', 'missing');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('InfluxDB connection [missing] is not configured.');

        DB::connection('influxdb');
    }

    public function testAnInfluxdbConnectionWithAnUnsupportedVersionIsRefused(): void
    {
        $this->app->get('config')->set('influxdb.connections.lake.version', 'v9');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('InfluxDB connection [lake] has an unsupported version [v9]; expected one of v1, v2, v3.');

        SqlConnection::fromConfig($this->app->get(InfluxDBManager::class), ['name' => 'influxdb', 'connection' => 'lake']);
    }

    public function testADriverNameIsReportedWithoutAConfiguredOne(): void
    {
        $this->assertSame('influxdb', (new SqlConnection(null, 'telegraf'))->getDriverName());
    }

    public function testAQueryIsOnePostOfItsSqlWithTheValuesEmbedded(): void
    {
        $this->responses->append(self::rows([['host' => "it's", 'usage_user' => 0.64]]));

        $query = DB::connection('influxdb')
            ->table('cpu')
            ->select('host', 'usage_user')
            ->where('host', "it's")
            ->where('path', 'C:\temp\\')
            ->where('ok', true)
            ->where('time', '>=', new DateTimeImmutable('2024-01-01 02:00:00', new DateTimeZone('+02:00')));

        $rows = $query->get();

        $this->assertInstanceOf(Collection::class, $rows);
        $this->assertEquals([(object) ['host' => "it's", 'usage_user' => 0.64]], $rows->all());

        $request = $this->lastRequest();

        $this->assertSame('POST http://lake.localhost:8181/api/v3/query_sql', $request->getMethod() . ' ' . $request->getUri());
        $this->assertSame('Token lake-token', $request->getHeaderLine('Authorization'));
        $this->assertSame([
            'db' => 'telegraf/autogen',
            'q' => 'select "host", "usage_user" from "cpu" where "host" = \'it\'\'s\' and "path" = \'C:\temp\\\' and "ok" = TRUE and "time" >= \'2024-01-01T02:00:00.000000+02:00\'',
            'format' => 'json',
        ], $this->lastQuery());
        $this->assertSame($query->toRawSql(), $this->lastQuery()['q']);
    }

    public function testRawSelectsRunOnTheConnection(): void
    {
        $this->responses->append(
            self::rows([['host' => 'web1', 'n' => 2], ['host' => 'web2', 'n' => 1]]),
            self::rows([['host' => 'web1', 'n' => 2]]),
            self::rows([['n' => 3]]),
        );

        $connection = DB::connection('influxdb');

        $this->assertEquals(
            [(object) ['host' => 'web1', 'n' => 2], (object) ['host' => 'web2', 'n' => 1]],
            $connection->select('select "host", count(*) as "n" from "cpu" where "time" > ? group by "host"', [new DateTimeImmutable('2024-01-01T00:00:00Z')]),
        );
        $this->assertEquals((object) ['host' => 'web1', 'n' => 2], $connection->selectOne('select "host", count(*) as "n" from "cpu" where "ok" = ? group by "host"', [false]));
        $this->assertSame(3, $connection->scalar('select count(*) as "n" from "cpu"'));
        $this->assertSame([
            'select "host", count(*) as "n" from "cpu" where "time" > \'2024-01-01T00:00:00.000000+00:00\' group by "host"',
            'select "host", count(*) as "n" from "cpu" where "ok" = FALSE group by "host"',
            'select count(*) as "n" from "cpu"',
        ], $this->statements());
    }

    /**
     * InfluxDB 3 leaves a column out of a row whose value is null, which would
     * leave pluck() and property reads short of the column.
     */
    public function testEveryRowHasTheColumnsInfluxdb3LeftOutForANull(): void
    {
        $this->responses->append(new Response(200, ['Content-Type' => 'application/json'], '[{"host":"web1","note":"x"},{"host":"web2"}]'));

        $this->assertSame(['web1' => 'x', 'web2' => null], DB::connection('influxdb')->table('cpu')->pluck('note', 'host')->all());
    }

    public function testAnEmptyResultIsAnEmptyCollection(): void
    {
        $this->responses->append(self::rows([]));

        $this->assertTrue(DB::connection('influxdb')->table('cpu')->get()->isEmpty());
    }

    public function testTheBuildersReadingMethodsWorkOnTheRows(): void
    {
        $this->responses->append(
            self::rows([['host' => 'web1', 'usage_user' => 0.64]]),
            self::rows([['usage_user' => 0.64]]),
            self::rows([['aggregate' => 2]]),
            self::rows([['aggregate' => 0.64]]),
            self::rows([['exists' => true]]),
            self::rows([]),
        );

        $table = static fn () => DB::connection('influxdb')->table('cpu');

        $this->assertEquals((object) ['host' => 'web1', 'usage_user' => 0.64], $table()->first());
        $this->assertSame(0.64, $table()->where('host', 'web1')->value('usage_user'));
        $this->assertSame(2, $table()->count());
        $this->assertSame(0.64, $table()->max('usage_user'));
        $this->assertTrue($table()->where('host', 'web1')->exists());
        $this->assertTrue($table()->where('host', 'nobody')->doesntExist());
        $this->assertSame([
            'select * from "cpu" limit 1',
            'select "usage_user" from "cpu" where "host" = \'web1\' limit 1',
            'select count(*) as "aggregate" from "cpu"',
            'select max("usage_user") as "aggregate" from "cpu"',
            'select true as "exists" where exists(select * from "cpu" where "host" = \'web1\')',
            'select true as "exists" where exists(select * from "cpu" where "host" = \'nobody\')',
        ], $this->statements());
    }

    public function testJoinsUnionsAndWindowFunctionsAreSentAsCompiled(): void
    {
        $this->responses->append(self::rows([]), self::rows([]), self::rows([]));

        $connection = DB::connection('influxdb');

        $connection->table('cpu')
            ->join('mem', static function (JoinClause $join): void {
                $join->on('cpu.host', '=', 'mem.host')->on('cpu.time', '=', 'mem.time');
            })
            ->select('cpu.host', 'cpu.usage_user', 'mem.used')
            ->where('mem.used', '>', 150)
            ->get();

        $connection->table('cpu')->select('host')->unionAll($connection->table('mem')->select('host'))->orderBy('host')->get();

        $connection->table('mem')->select('host', 'used')->selectRaw('sum("used") over (order by "time") as "running"')->orderBy('time')->get();

        $this->assertSame([
            'select "cpu"."host", "cpu"."usage_user", "mem"."used" from "cpu" inner join "mem" on "cpu"."host" = "mem"."host" and "cpu"."time" = "mem"."time" where "mem"."used" > 150',
            '(select "host" from "cpu") union all (select "host" from "mem") order by "host" asc',
            'select "host", "used", sum("used") over (order by "time") as "running" from "mem" order by "time" asc',
        ], $this->statements());
    }

    public function testAnEloquentModelReadsThroughTheConnection(): void
    {
        $this->responses->append(self::rows([['host' => 'web1', 'time' => '2024-01-01T00:00:00', 'usage_user' => 0.64]]));

        $cpu = Cpu::query()->where('host', 'web1')->first();

        $this->assertInstanceOf(Cpu::class, $cpu);
        $this->assertSame('web1', $cpu->host);
        $this->assertSame(0.64, $cpu->usage_user);
        $this->assertSame('2024-01-01 00:00:00', $cpu->time->format('Y-m-d H:i:s'));
        $this->assertSame('select * from "cpu" where "host" = \'web1\' limit 1', $this->lastQuery()['q']);
    }

    public function testAStatementTheServerRefusesIsAQueryExceptionCarryingTheStatement(): void
    {
        $this->responses->append(new Response(400, ['Content-Type' => 'text/plain'], "Error during planning: table 'public.iox.nothere' not found"));

        try {
            DB::connection('influxdb')->table('nothere')->where('host', 'web1')->get();

            $this->fail('No exception was thrown.');
        } catch (QueryException $exception) {
            $this->assertInstanceOf(ApiException::class, $exception->getPrevious());
            $this->assertSame('influxdb', $exception->getConnectionName());
            $this->assertSame('select * from "nothere" where "host" = ?', $exception->getSql());
            $this->assertSame(['web1'], $exception->getBindings());
            $this->assertSame('select * from "nothere" where "host" = \'web1\'', $exception->getRawSql());
            $this->assertStringContainsString("Error during planning: table 'public.iox.nothere' not found", $exception->getMessage());
            $this->assertStringEndsWith('(Connection: influxdb, Host: lake.localhost, Port: 8181, Database: telegraf/autogen, SQL: select * from "nothere" where "host" = web1)', $exception->getMessage());
            $this->assertCount(1, $this->history);
        }
    }

    /**
     * A statement that fails once its rows have started streaming leaves the JSON unfinished.
     */
    public function testAnAnswerThatIsNotRowsIsAQueryException(): void
    {
        $this->responses->append(new Response(200, ['Content-Type' => 'application/json'], '[{"host":"web1"},{"ho'));

        try {
            DB::connection('influxdb')->select('select "host" from "cpu"');

            $this->fail('No exception was thrown.');
        } catch (QueryException $exception) {
            $this->assertInstanceOf(ApiException::class, $exception->getPrevious());
            $this->assertStringStartsWith('The /api/v3/query_sql response is not JSON: ', $exception->getMessage());
        }
    }

    public function testALostConnectionIsReestablishedAndTheStatementTriedOnceMore(): void
    {
        $this->responses->append(
            new ConnectException('Connection reset by peer', new Request('POST', '/api/v3/query_sql')),
            self::rows([['host' => 'web1']]),
        );

        $connection = DB::connection('influxdb');
        $api = $connection->getApi();

        $this->assertEquals([(object) ['host' => 'web1']], $connection->table('cpu')->get()->all());
        $this->assertNotSame($api, $connection->getApi());
        $this->assertSame('telegraf/autogen', $connection->getDatabaseName());
        $this->assertSame(['POST /api/v3/query_sql', 'POST /api/v3/query_sql'], $this->requestLines());
    }

    #[DataProvider('writes')]
    public function testEveryWriteIsRefusedBeforeAnythingIsSent(Closure $write): void
    {
        try {
            $write(DB::connection('influxdb'));

            $this->fail('No exception was thrown.');
        } catch (LogicException $exception) {
            $this->assertSame('InfluxDB 3 SQL is read-only; write points through InfluxDB::writeApi().', $exception->getMessage());
            $this->assertSame([], $this->history);
        }
    }

    /**
     * @return array<string, array{Closure(Connection): mixed}>
     */
    public static function writes(): array
    {
        return [
            'insert' => [static fn (Connection $db): mixed => $db->table('cpu')->insert(['host' => 'web1'])],
            'insertGetId' => [static fn (Connection $db): mixed => $db->table('cpu')->insertGetId(['host' => 'web1'])],
            'insertOrIgnore' => [static fn (Connection $db): mixed => $db->table('cpu')->insertOrIgnore(['host' => 'web1'])],
            'upsert' => [static fn (Connection $db): mixed => $db->table('cpu')->upsert([['host' => 'web1', 'usage_user' => 1]], ['host'])],
            'update' => [static fn (Connection $db): mixed => $db->table('cpu')->where('host', 'web1')->update(['usage_user' => 1])],
            'increment' => [static fn (Connection $db): mixed => $db->table('cpu')->increment('usage_user')],
            'delete' => [static fn (Connection $db): mixed => $db->table('cpu')->where('host', 'web1')->delete()],
            'truncate' => [static fn (Connection $db): mixed => $db->table('cpu')->truncate()],
            'a raw insert' => [static fn (Connection $db): mixed => $db->insert('insert into "cpu" ("host") values (?)', ['web1'])],
            'a raw update' => [static fn (Connection $db): mixed => $db->update('update "cpu" set "usage_user" = ?', [1])],
            'a raw delete' => [static fn (Connection $db): mixed => $db->delete('delete from "cpu"')],
            'a statement' => [static fn (Connection $db): mixed => $db->statement('create table "t" ("a" int)')],
            'an affecting statement' => [static fn (Connection $db): mixed => $db->affectingStatement('delete from "cpu"')],
            'an unprepared statement' => [static fn (Connection $db): mixed => $db->unprepared('drop table "cpu"')],
            'an Eloquent create' => [static fn (): mixed => Cpu::query()->create(['host' => 'web1'])],
        ];
    }

    #[DataProvider('transactions')]
    public function testTransactionsAreRefused(Closure $transaction): void
    {
        $connection = DB::connection('influxdb');

        try {
            $transaction($connection);

            $this->fail('No exception was thrown.');
        } catch (LogicException $exception) {
            $this->assertSame('Database driver [influxdb] does not support transactions.', $exception->getMessage());
        }

        $this->assertFalse($connection->inTransaction());
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertSame([], $this->history);
    }

    /**
     * @return array<string, array{Closure(Connection): mixed}>
     */
    public static function transactions(): array
    {
        return [
            'transaction' => [static fn (Connection $db): mixed => $db->transaction(static fn (): null => null)],
            'beginTransaction' => [static fn (Connection $db): mixed => $db->beginTransaction()],
        ];
    }

    public function testPretendingSendsNothingAndLogsTheStatementAsItWouldBeSent(): void
    {
        $queries = DB::connection('influxdb')->pretend(function (Connection $db): void {
            $this->assertSame([], $db->table('cpu')->where('host', 'web1')->get()->all());
        });

        $this->assertSame('select * from "cpu" where "host" = \'web1\'', $queries[0]['query']);
        $this->assertSame([], $this->history);
    }

    public function testPretendingLogsADateBindingAsTheStringTheConnectionWouldWrite(): void
    {
        $queries = DB::connection('influxdb')->pretend(function (Connection $db): void {
            $db->table('cpu')->where('time', '>=', new DateTimeImmutable('2024-01-01T00:00:00Z'))->get();
        });

        $this->assertSame(
            'select * from "cpu" where "time" >= \'2024-01-01T00:00:00.000000+00:00\'',
            $queries[0]['query'],
        );
        $this->assertSame([], $this->history);
    }

    public function testACursorYieldsTheRowsOfOneRequestOnlyOnceIteratedOver(): void
    {
        $this->responses->append(self::rows([['host' => 'web1'], ['host' => 'web2']]), self::rows([['host' => 'web3']]));

        $connection = DB::connection('influxdb');
        $cursor = $connection->table('cpu')->select('host')->cursor();

        $this->assertSame([], $this->history);
        $this->assertSame(['web1', 'web2'], $cursor->pluck('host')->all());
        $this->assertEquals([(object) ['host' => 'web3']], iterator_to_array($connection->cursor('select "host" from "mem"')));
        $this->assertSame(['select "host" from "cpu"', 'select "host" from "mem"'], $this->statements());
    }

    public function testTheServersVersionAndHealthComeFromPing(): void
    {
        $this->responses->append(
            self::about(['product_name' => 'InfluxDB 3 Core', 'version' => '3.11.5', 'revision' => 'f083f73c92']),
            self::about(['product_name' => 'InfluxDB 3 Core', 'version' => '3.11.5']),
            new Response(503, [], 'unavailable'),
            self::about(['product_name' => 'InfluxDB 3 Core']),
            self::about(['version' => 3]),
        );

        $connection = DB::connection('influxdb');

        $this->assertSame('3.11.5', $connection->getServerVersion());
        $this->assertTrue($connection->ping());
        $this->assertFalse($connection->ping());
        $this->assertSame('', $connection->getServerVersion());
        $this->assertSame('', $connection->getServerVersion());
        $this->assertSame(array_fill(0, 5, 'GET /ping'), $this->requestLines());
    }

    public function testACancelledPingIsNotTakenForAnUnhealthyServer(): void
    {
        $this->responses->append(new CanceledException('The heartbeat was canceled.'));

        $this->expectException(CanceledException::class);

        DB::connection('influxdb')->ping();
    }

    public function testADisconnectedConnectionLetsGoOfItsTransportAndReconnectsForItsNextStatement(): void
    {
        $this->responses->append(self::rows([]), self::about(['version' => '3.11.5']));

        $connection = DB::connection('influxdb');
        $api = $connection->getApi();

        $connection->disconnect();

        $this->assertTrue($connection->ping(), 'A connection with nothing open has nothing to fail.');
        $this->assertSame([], $this->history);

        $connection->table('cpu')->get();

        $this->assertNotSame($api, $reconnected = $connection->getApi());
        $this->assertSame($connection, DB::reconnect('influxdb'));
        $this->assertNotSame($reconnected, $connection->getApi());

        $connection->disconnect();

        $this->assertSame('3.11.5', $connection->getServerVersion());
        $this->assertSame(['POST /api/v3/query_sql', 'GET /ping'], $this->requestLines());
    }

    /**
     * Configure the InfluxDB 3 connection `lake` on the mocked transport, and the database connection `influxdb` on it.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $this->responses = new MockHandler;

        $stack = HandlerStack::create($this->responses);
        $stack->push(Middleware::history($this->history));

        $config = $app->get('config');

        $config->set('influxdb.connections.lake', [
            'version' => 'v3',
            'url' => 'http://lake.localhost:8181',
            'token' => 'lake-token',
            'bucket' => 'telegraf/autogen',
            'org' => 'lake-org',
            'httpClient' => new Guzzle(['handler' => $stack]),
        ]);

        $config->set('database.connections.influxdb', [
            'driver' => 'influxdb',
            'connection' => 'lake',
        ]);
    }

    /**
     * A 200 answer carrying the given rows, as InfluxDB 3 sends them.
     *
     * @param list<array<string, mixed>> $rows
     */
    private static function rows(array $rows): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($rows, JSON_THROW_ON_ERROR));
    }

    /**
     * A 200 answer to /ping, as InfluxDB 3 sends one.
     *
     * @param array<string, mixed> $about
     */
    private static function about(array $about): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($about, JSON_THROW_ON_ERROR));
    }

    /**
     * The most recent request sent through the mocked transport.
     */
    private function lastRequest(): RequestInterface
    {
        $this->assertNotEmpty($this->history, 'No request was sent.');

        return $this->history[array_key_last($this->history)]['request'];
    }

    /**
     * The JSON body of the most recent request.
     *
     * @return array<string, mixed>
     */
    private function lastQuery(): array
    {
        return json_decode((string) $this->lastRequest()->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * The statement of every SQL request sent, oldest first.
     *
     * @return list<string>
     */
    private function statements(): array
    {
        return array_map(
            static fn (array $entry): string => json_decode((string) $entry['request']->getBody(), true, 512, JSON_THROW_ON_ERROR)['q'],
            array_values(array_filter(
                $this->history,
                static fn (array $entry): bool => $entry['request']->getUri()->getPath() === '/api/v3/query_sql',
            )),
        );
    }

    /**
     * The method and path of every request sent, oldest first, such as `GET /ping`.
     *
     * @return list<string>
     */
    private function requestLines(): array
    {
        return array_map(
            static fn (array $entry): string => $entry['request']->getMethod() . ' ' . $entry['request']->getUri()->getPath(),
            $this->history,
        );
    }
}
