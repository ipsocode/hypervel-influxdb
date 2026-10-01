<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature\InfluxQL;

use DateTimeImmutable;
use GuzzleHttp\Psr7\Response;
use Hypervel\Foundation\Testing\Attributes\UnitTest;
use InfluxDB2\ApiException;
use InvalidArgumentException;
use Ipsocode\InfluxDB\InfluxQL\Builder;
use Ipsocode\InfluxDB\InfluxQL\Connection;
use Ipsocode\InfluxDB\InfluxQL\Expression;
use Ipsocode\InfluxDB\InfluxQL\Grammars\Grammar;
use Ipsocode\InfluxDB\InfluxQL\Grammars\V1Grammar;
use Ipsocode\InfluxDB\InfluxQL\Grammars\V2Grammar;
use Ipsocode\InfluxDB\InfluxQL\Grammars\V3Grammar;
use Ipsocode\InfluxDB\InfluxQL\QueryApi;
use Ipsocode\InfluxDB\InfluxQL\QueryException;
use Ipsocode\InfluxDB\InfluxQL\Result;
use Ipsocode\InfluxDB\InfluxQL\V1Connection;
use Ipsocode\InfluxDB\InfluxQL\V2Connection;
use Ipsocode\InfluxDB\InfluxQL\V3Connection;
use Ipsocode\InfluxDB\InfluxQL\Version;
use Ipsocode\InfluxDB\Tests\Concerns\MocksInfluxQL;
use Ipsocode\InfluxDB\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

class ConnectionTest extends TestCase
{
    use MocksInfluxQL;

    #[UnitTest]
    public function testTheConnectionIsNamedFromItsConfig(): void
    {
        $this->assertSame('analytics', $this->connection(config: ['name' => 'analytics'])->getName());
        $this->assertSame('default', Connection::make($this->client())->getName());
    }

    #[UnitTest]
    #[DataProvider('versions')]
    public function testMakePicksTheConnectionForTheConfiguredVersionInAnySpelling(mixed $version, string $class, Version $expected): void
    {
        $connection = $this->connection(config: ['version' => $version]);

        $this->assertInstanceOf($class, $connection);
        $this->assertSame($expected, $connection->getVersion());
    }

    /**
     * @return array<string, array{mixed, class-string<Connection>, Version}>
     */
    public static function versions(): array
    {
        return [
            'unset' => [null, V1Connection::class, Version::V1],
            'empty' => ['', V1Connection::class, Version::V1],
            'v1' => ['v1', V1Connection::class, Version::V1],
            'V1' => ['V1', V1Connection::class, Version::V1],
            'the V1 case' => [Version::V1, V1Connection::class, Version::V1],
            'v2' => ['v2', V2Connection::class, Version::V2],
            'V2' => ['V2', V2Connection::class, Version::V2],
            'the V2 case' => [Version::V2, V2Connection::class, Version::V2],
            'v3' => ['v3', V3Connection::class, Version::V3],
            'V3' => ['V3', V3Connection::class, Version::V3],
            'the V3 case' => [Version::V3, V3Connection::class, Version::V3],
        ];
    }

    #[UnitTest]
    public function testAConnectionThatNamesNoVersionIsV1(): void
    {
        $this->assertInstanceOf(V1Connection::class, $this->connection());
        $this->assertInstanceOf(V1Connection::class, Connection::make($this->client()));
    }

    #[UnitTest]
    #[DataProvider('unsupportedVersions')]
    public function testAVersionThisPackageDoesNotImplementIsRefused(mixed $version, string $named): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs("InfluxDB connection [main] has an unsupported version [{$named}]; expected one of v1, v2, v3.");

        $this->connection(config: ['version' => $version]);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function unsupportedVersions(): array
    {
        return [
            'v4' => ['v4', 'v4'],
            'a release rather than a major version' => ['2.7', '2.7'],
            'a bare number' => [1, '1'],
            'an object' => [new stdClass, 'stdClass'],
        ];
    }

    #[UnitTest]
    public function testAnUnnamedConnectionIsCalledDefaultWhenItsVersionIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('InfluxDB connection [default] has an unsupported version [v4]; expected one of v1, v2, v3.');

        Connection::make($this->client(), ['version' => 'v4']);
    }

    #[UnitTest]
    #[DataProvider('grammars')]
    public function testEachVersionCompilesWithItsOwnGrammar(string $version, string $grammar): void
    {
        $connection = $this->connection(config: ['version' => $version]);

        $this->assertInstanceOf($grammar, $connection->getQueryGrammar());
        $this->assertSame($connection->getQueryGrammar(), $connection->query()->getGrammar());
    }

    /**
     * @return array<string, array{string, class-string<Grammar>}>
     */
    public static function grammars(): array
    {
        return [
            'v1' => ['v1', V1Grammar::class],
            'v2' => ['v2', V2Grammar::class],
            'v3' => ['v3', V3Grammar::class],
        ];
    }

    #[UnitTest]
    #[DataProvider('addressing')]
    public function testTheDatabaseAndRetentionPolicyComeFromTheInfluxqlBlockOrTheBucket(string $version, string $bucket, array $influxql, ?string $database, ?string $retentionPolicy): void
    {
        $connection = $this->connection(config: ['version' => $version, 'bucket' => $bucket, 'influxql' => $influxql]);

        $this->assertSame($database, $connection->getDatabase());
        $this->assertSame($retentionPolicy, $connection->getRetentionPolicy());
    }

    /**
     * Every case for 1.x and 2.x: 1.x splits a `db/rp` bucket at its first
     * slash, and 2.x derives a bucket's virtual DBRP mapping the same way.
     *
     * @return array<string, array{string, string, array<string, mixed>, ?string, ?string}>
     */
    public static function addressing(): array
    {
        $cases = [
            'a bucket is the database' => ['telegraf', [], 'telegraf', null],
            'a db/rp bucket splits at the slash' => ['telegraf/autogen', [], 'telegraf', 'autogen'],
            'only the first slash splits' => ['telegraf/two/weeks', [], 'telegraf', 'two/weeks'],
            'an empty policy is the default one' => ['telegraf/', [], 'telegraf', null],
            'an empty database is none' => ['/autogen', [], null, 'autogen'],
            'the block names the database' => ['telegraf/autogen', ['database' => 'metrics'], 'metrics', null],
            'the block names both' => ['telegraf/autogen', ['database' => 'metrics', 'retentionPolicy' => 'weekly'], 'metrics', 'weekly'],
            'the block overrides the bucket policy' => ['telegraf/autogen', ['retentionPolicy' => 'weekly'], 'telegraf', 'weekly'],
            'empty values in the block are unset' => ['telegraf/autogen', ['database' => '', 'retentionPolicy' => ''], 'telegraf', 'autogen'],
        ];

        return self::forEachVersion($cases, Version::V1, Version::V2);
    }

    #[UnitTest]
    #[DataProvider('influxdb3Addressing')]
    public function testAnInfluxdb3ConnectionReadsTheBucketWholeOrTheDatabaseTheBlockNames(string $bucket, array $influxql, ?string $database): void
    {
        $connection = $this->connection(config: ['version' => 'v3', 'bucket' => $bucket, 'influxql' => $influxql]);

        $this->assertSame($database, $connection->getDatabase());
        $this->assertNull($connection->getRetentionPolicy());
    }

    /**
     * InfluxDB 3 stores what the 2.x API writes under the whole bucket name,
     * slashes included, and has no retention policies.
     *
     * @return array<string, array{string, array<string, mixed>, ?string}>
     */
    public static function influxdb3Addressing(): array
    {
        return [
            'a bucket is the database' => ['telegraf', [], 'telegraf'],
            'a slash is part of the name' => ['telegraf/autogen', [], 'telegraf/autogen'],
            'so is every slash' => ['telegraf/two/weeks', [], 'telegraf/two/weeks'],
            'the block names the database' => ['telegraf/autogen', ['database' => 'metrics'], 'metrics'],
            'empty values in the block are unset' => ['telegraf/autogen', ['database' => '', 'retentionPolicy' => ''], 'telegraf/autogen'],
        ];
    }

    #[UnitTest]
    public function testAnInfluxdb3ConnectionRefusesARetentionPolicy(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('InfluxDB connection [main] has an influxql.retentionPolicy [weekly], but InfluxDB 3 has no retention policies; name the database instead.');

        $this->connection(config: ['version' => 'v3', 'bucket' => 'telegraf', 'influxql' => ['database' => 'telegraf', 'retentionPolicy' => 'weekly']]);
    }

    #[UnitTest]
    public function testTheBucketFallsBackToTheClientsOptions(): void
    {
        $connection = Connection::make($this->client(options: ['bucket' => 'telegraf/autogen']));

        $this->assertSame('telegraf', $connection->getDatabase());
        $this->assertSame('autogen', $connection->getRetentionPolicy());

        $influxdb3 = Connection::make($this->client(options: ['bucket' => 'telegraf/autogen']), ['version' => 'v3']);

        $this->assertSame('telegraf/autogen', $influxdb3->getDatabase());
        $this->assertNull($influxdb3->getRetentionPolicy());
    }

    #[UnitTest]
    #[DataProvider('everyVersion')]
    public function testANonStringBucketAddressesNoDatabase(string $version): void
    {
        $connection = Connection::make($this->client(), ['version' => $version, 'bucket' => 42]);

        $this->assertNull($connection->getDatabase());
        $this->assertNull($connection->getRetentionPolicy());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function everyVersion(): array
    {
        return self::forEachVersion(['' => []]);
    }

    #[UnitTest]
    #[DataProvider('epochs')]
    public function testTheEpochIsAPrecisionTheEndpointReads(string $version, ?string $epoch, ?string $sent): void
    {
        $this->assertSame($sent, $this->connection(config: ['version' => $version, 'influxql' => ['epoch' => $epoch]])->getEpoch());
    }

    /**
     * Every version reads the precisions 1.x defines, `µ` as `u`, the only
     * spelling 1.x and 2.x read; InfluxDB 3 reads days and weeks as well.
     *
     * @return array<string, array{string, ?string, ?string}>
     */
    public static function epochs(): array
    {
        return [
            ...self::forEachVersion([
                'unset' => [null, null],
                'empty' => ['', null],
                'ns' => ['ns', 'ns'],
                'u' => ['u', 'u'],
                'µ is sent as u, the one spelling every version reads' => ['µ', 'u'],
                'ms' => ['ms', 'ms'],
                's' => ['s', 's'],
                'm' => ['m', 'm'],
                'h' => ['h', 'h'],
            ]),
            ...self::forEachVersion([
                'd' => ['d', 'd'],
                'w' => ['w', 'w'],
            ], Version::V3),
        ];
    }

    #[UnitTest]
    #[DataProvider('epochsTheEndpointDoesNotRead')]
    public function testAnEpochTheEndpointDoesNotReadIsRefused(string $version, string $epoch, string $expected): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs("InfluxDB connection [main] has an invalid influxql.epoch [{$epoch}]; expected one of {$expected}.");

        $this->connection(config: ['version' => $version, 'influxql' => ['epoch' => $epoch]]);
    }

    /**
     * No version reads `us`, and only InfluxDB 3 reads days and weeks.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function epochsTheEndpointDoesNotRead(): array
    {
        $before3 = 'ns, u, µ, ms, s, m, h';

        return [
            ...self::forEachVersion([
                'us' => ['us', $before3],
                'd' => ['d', $before3],
                'w' => ['w', $before3],
            ], Version::V1, Version::V2),
            ...self::forEachVersion([
                'us' => ['us', 'ns, u, µ, ms, s, m, h, d, w'],
            ], Version::V3),
        ];
    }

    #[UnitTest]
    #[DataProvider('versionsWithRetentionPolicies')]
    public function testEveryStatementIsSentWithTheDatabaseRetentionPolicyAndEpoch(string $version): void
    {
        $connection = $this->connection([self::emptyResponse()], ['version' => $version, 'bucket' => 'telegraf/autogen', 'influxql' => ['epoch' => 'µ']]);

        $connection->select('SELECT * FROM "cpu"');

        $this->assertSame(['db' => 'telegraf', 'rp' => 'autogen', 'epoch' => 'u'], $this->lastQueryParameters());
        $this->assertSame('SELECT * FROM "cpu"', $this->lastStatement());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function versionsWithRetentionPolicies(): array
    {
        return self::forEachVersion(['' => []], Version::V1, Version::V2);
    }

    #[UnitTest]
    public function testAnInfluxdb3StatementIsSentWithTheWholeBucketAndNoRetentionPolicy(): void
    {
        $connection = $this->connection([self::emptyResponse()], ['version' => 'v3', 'bucket' => 'telegraf/autogen', 'influxql' => ['epoch' => 'w']]);

        $connection->select('SELECT * FROM "cpu"');

        $this->assertSame(['db' => 'telegraf/autogen', 'epoch' => 'w'], $this->lastQueryParameters());
        $this->assertSame('SELECT * FROM "cpu"', $this->lastStatement());
    }

    #[UnitTest]
    public function testSelectEmbedsTheBindingsAndReturnsTheRowsOfTheFirstResult(): void
    {
        $connection = $this->connection([self::response([
            ['statement_id' => 0, 'series' => [
                self::series('cpu', ['time', 'value'], [['2024-01-01T00:00:00Z', 1]], ['host' => 'web1']),
                self::series('cpu', ['time', 'value'], [['2024-01-01T00:00:00Z', 2]], ['host' => 'web2']),
            ]],
            ['statement_id' => 1, 'series' => [self::series('mem', ['time', 'value'], [['2024-01-01T00:00:00Z', 3]])]],
        ])]);

        $rows = $connection->select('SELECT "value" FROM "cpu" WHERE "time" >= ? GROUP BY "host"; SELECT "value" FROM "mem"', [new DateTimeImmutable('2024-01-01T00:00:00Z')]);

        $this->assertSame([
            ['time' => '2024-01-01T00:00:00Z', 'value' => 1, 'host' => 'web1'],
            ['time' => '2024-01-01T00:00:00Z', 'value' => 2, 'host' => 'web2'],
        ], array_map(fn (stdClass $row): array => (array) $row, $rows));
        $this->assertSame('SELECT "value" FROM "cpu" WHERE "time" >= \'2024-01-01T00:00:00.000000Z\' GROUP BY "host"; SELECT "value" FROM "mem"', $this->lastStatement());
    }

    #[UnitTest]
    public function testSelectIsEmptyForAResponseWithNoResults(): void
    {
        $this->assertSame([], $this->connection([self::response([])])->select('SELECT * FROM "cpu"'));
    }

    #[UnitTest]
    public function testSelectOneReturnsTheFirstRowOrNull(): void
    {
        $connection = $this->connection([
            self::seriesResponse([self::series('cpu', ['time', 'value'], [['2024-01-01T00:00:00Z', 1], ['2024-01-01T00:10:00Z', 2]])]),
            self::emptyResponse(),
        ]);

        $this->assertSame(['time' => '2024-01-01T00:00:00Z', 'value' => 1], (array) $connection->selectOne('SELECT "value" FROM "cpu"'));
        $this->assertNull($connection->selectOne('SELECT "value" FROM "cpu"'));
    }

    #[UnitTest]
    public function testResultsReturnsOneResultPerStatement(): void
    {
        $connection = $this->connection([self::response([
            ['statement_id' => 0, 'series' => [self::series('cpu', ['time', 'count'], [['1970-01-01T00:00:00Z', 5]])]],
            ['statement_id' => 1],
        ])]);

        $results = $connection->results('SELECT COUNT("value") FROM "cpu"; SELECT COUNT("value") FROM "mem"');

        $this->assertCount(2, $results);
        $this->assertContainsOnlyInstancesOf(Result::class, $results);
        $this->assertSame([0, 1], array_map(fn (Result $result): int => $result->statementId, $results));
        $this->assertSame([], $results[1]->series);
    }

    /**
     * InfluxDB 2.x returns no result for a DELETE or DROP MEASUREMENT that
     * succeeds: it answers `{}` when no statement returned one, and otherwise
     * leaves theirs out of the list.
     */
    #[UnitTest]
    public function testAStatementTheServerAnswersWithNoResultSucceeds(): void
    {
        $connection = $this->connection([
            self::noResultsResponse(),
            self::noResultsResponse(),
            self::response([['statement_id' => 1, 'series' => [self::series('cpu', ['time', 'value'], [['2024-01-01T00:00:00Z', 1]])]]]),
        ], ['version' => 'v2']);

        $this->assertTrue($connection->statement('DELETE FROM "cpu" WHERE "host" = ?', ['web1']));
        $this->assertSame([], $connection->results('DROP MEASUREMENT "cpu"'));

        $results = $connection->results('DELETE FROM "cpu"; SELECT * FROM "cpu"');

        $this->assertCount(1, $results);
        $this->assertSame(1, $results[0]->statementId);
        $this->assertSame(['DELETE FROM "cpu" WHERE "host" = \'web1\'', 'DROP MEASUREMENT "cpu"', 'DELETE FROM "cpu"; SELECT * FROM "cpu"'], $this->statements());
    }

    #[UnitTest]
    public function testAnyRefusedStatementFailsTheWholeRequest(): void
    {
        $connection = $this->connection([self::response([
            ['statement_id' => 0, 'series' => [self::series('cpu', ['time', 'value'], [['1970-01-01T00:00:00Z', 5]])]],
            ['statement_id' => 1, 'error' => 'measurement not found'],
        ])]);

        try {
            $connection->results('SELECT * FROM "cpu"; SELECT * FROM ?', [new Expression('"missing"')]);

            $this->fail('No exception was thrown.');
        } catch (QueryException $exception) {
            $this->assertSame('measurement not found (Connection: main, InfluxQL: SELECT * FROM "cpu"; SELECT * FROM "missing")', $exception->getMessage());
            $this->assertSame('SELECT * FROM "cpu"; SELECT * FROM "missing"', $exception->getSql());
        }
    }

    #[UnitTest]
    public function testStatementRunsTheStatementAndReportsTrue(): void
    {
        $connection = $this->connection([self::emptyResponse()]);

        $this->assertTrue($connection->statement('SELECT MEAN(*) INTO "cpu_hourly" FROM "cpu" WHERE "host" = ? GROUP BY time(1h)', ['web1']));
        $this->assertSame('SELECT MEAN(*) INTO "cpu_hourly" FROM "cpu" WHERE "host" = \'web1\' GROUP BY time(1h)', $this->lastStatement());
    }

    #[UnitTest]
    public function testATransportFailureIsAQueryExceptionWithTheStatementAsSent(): void
    {
        $connection = $this->connection([new Response(401, [], '{"error":"authorization failed"}')], ['name' => 'analytics']);

        try {
            $connection->select('SELECT * FROM "cpu" WHERE "host" = ?', ['web1']);

            $this->fail('No exception was thrown.');
        } catch (QueryException $exception) {
            $this->assertInstanceOf(ApiException::class, $exception->getPrevious());
            $this->assertSame(401, $exception->getCode());
            $this->assertSame('analytics', $exception->getConnectionName());
            $this->assertSame('SELECT * FROM "cpu" WHERE "host" = \'web1\'', $exception->getSql());
            $this->assertSame(['web1'], $exception->getBindings());
            $this->assertStringContainsString('authorization failed', $exception->getMessage());
        }
    }

    #[UnitTest]
    public function testAResponseThatIsNotAQueryResponseIsAQueryException(): void
    {
        $connection = $this->connection([new Response(200, [], '<html>proxy error</html>')]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageIs('The /query response is not JSON: Syntax error (Connection: main, InfluxQL: SELECT * FROM "cpu")');

        $connection->select('SELECT * FROM "cpu"');
    }

    #[UnitTest]
    public function testPrepareBindingsFormatsDatesAndLeavesTheRest(): void
    {
        $this->assertSame(
            ['2024-01-01T00:00:00.000000Z', 'web1', 5, null],
            $this->connection()->prepareBindings([new DateTimeImmutable('2024-01-01T01:00:00+01:00'), 'web1', 5, null]),
        );
    }

    #[UnitTest]
    public function testRawMakesAnExpression(): void
    {
        $expression = $this->connection()->raw(5);

        $this->assertInstanceOf(Expression::class, $expression);
        $this->assertSame(5, $expression->getValue(new V1Grammar));
    }

    #[UnitTest]
    public function testTableAndQueryStartABuilderOnTheConnection(): void
    {
        $connection = $this->connection();

        $table = $connection->table('cpu');
        $query = $connection->query();

        $this->assertInstanceOf(Builder::class, $table);
        $this->assertSame('cpu', $table->from);
        $this->assertSame($connection, $table->getConnection());
        $this->assertSame($connection->getQueryGrammar(), $table->getGrammar());
        $this->assertNull($query->from);
        $this->assertSame($connection, $query->getConnection());
    }

    #[UnitTest]
    public function testTheTransportIsBuiltFromTheClientsOptionsUnlessOneIsGiven(): void
    {
        $client = $this->client([self::emptyResponse()]);

        $built = new V1Connection($client);

        $this->assertInstanceOf(QueryApi::class, $built->getQueryApi());
        $this->assertSame($client, $built->getClient());

        $api = new QueryApi($client->options);
        $grammar = new V1Grammar;

        $given = new V1Connection($client, [], $api, $grammar);

        $this->assertSame($api, $given->getQueryApi());
        $this->assertSame($grammar, $given->getQueryGrammar());
        $this->assertNotSame($built->getQueryGrammar(), $given->getQueryGrammar());

        $given->statement('SELECT * FROM "cpu"');

        $this->assertSame('SELECT * FROM "cpu"', $this->lastStatement());
    }

    #[UnitTest]
    public function testStatementsRunOnTheConnectionAreSentWithoutReadingTheServersVersion(): void
    {
        $connection = $this->connection([self::emptyResponse(), self::emptyResponse()]);

        $connection->select('SELECT * FROM "cpu"');
        $connection->statement('DELETE FROM "cpu"');

        $this->assertSame(['POST /query', 'POST /query'], $this->requestLines());
    }

    #[UnitTest]
    public function testTheServersVersionIsReadFromPingAndKeptOnceNamed(): void
    {
        $connection = $this->connection([self::pingResponse('v2.7.12')]);

        $this->assertFalse($connection->knowsServerVersion());
        $this->assertSame('v2.7.12', $connection->getServerVersion());
        $this->assertTrue($connection->knowsServerVersion());
        $this->assertSame('v2.7.12', $connection->getServerVersion());
        $this->assertSame(['GET /ping'], $this->requestLines());
    }

    #[UnitTest]
    public function testAServerVersionSetOnTheConnectionIsKnownWithoutAskingTheServer(): void
    {
        $connection = $this->connection([self::pingResponse('1.8.10')])->setServerVersion('v2.7.12');

        $this->assertTrue($connection->knowsServerVersion());
        $this->assertSame('v2.7.12', $connection->getServerVersion());
        $this->assertSame([], $this->history);

        $connection->setServerVersion(null);

        $this->assertFalse($connection->knowsServerVersion());
        $this->assertSame('1.8.10', $connection->getServerVersion());
        $this->assertSame(['GET /ping'], $this->requestLines());
    }

    #[UnitTest]
    public function testAServerThatNamesNoVersionIsAskedAgain(): void
    {
        $connection = $this->connection([self::pingResponse(null), self::pingResponse('1.8.10')]);

        $this->assertNull($connection->getServerVersion());
        $this->assertFalse($connection->knowsServerVersion());
        $this->assertSame('1.8.10', $connection->getServerVersion());
        $this->assertTrue($connection->knowsServerVersion());
        $this->assertSame(['GET /ping', 'GET /ping'], $this->requestLines());
    }

    /**
     * Repeat each case of a data provider for the given versions, or every version, the version first.
     *
     * @param array<string, list<mixed>> $cases
     * @return array<string, list<mixed>>
     */
    private static function forEachVersion(array $cases, Version ...$versions): array
    {
        $versioned = [];

        foreach ($versions ?: Version::cases() as $version) {
            foreach ($cases as $name => $case) {
                $versioned[trim($version->value . ': ' . $name, ': ')] = [$version->value, ...$case];
            }
        }

        return $versioned;
    }
}
