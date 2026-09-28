<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature\InfluxQL;

use ArrayIterator;
use BadMethodCallException;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Closure;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Foundation\Testing\Attributes\UnitTest;
use Hypervel\Support\Collection;
use InfluxDB2\ApiException;
use InvalidArgumentException;
use Ipsocode\InfluxDB\InfluxQL\Builder;
use Ipsocode\InfluxDB\InfluxQL\Expression;
use Ipsocode\InfluxDB\InfluxQL\Grammars\V1Grammar;
use Ipsocode\InfluxDB\InfluxQL\QueryException;
use Ipsocode\InfluxDB\InfluxQL\Regex;
use Ipsocode\InfluxDB\InfluxQL\Series;
use Ipsocode\InfluxDB\InfluxQL\V1Connection;
use Ipsocode\InfluxDB\Tests\Concerns\MocksInfluxQL;
use Ipsocode\InfluxDB\Tests\Fixtures\Region;
use Ipsocode\InfluxDB\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use SortDirection;
use stdClass;
use Throwable;

/**
 * The builder run against a mocked transport: what it sends, and what it makes of the answer.
 *
 * GrammarTest covers what each clause compiles to; this covers the methods
 * that execute a query, the guards that refuse what InfluxQL cannot express,
 * and the parts of the builder that hold state — bindings, clones, macros.
 */
class BuilderTest extends TestCase
{
    use MocksInfluxQL;

    protected function setUp(): void
    {
        parent::setUp();

        // The builder reads the server's version before its first statement,
        // so every connection's mocked server answers that /ping as 1.x first.
        $this->pingVersion = '1.8.10';
    }

    #[UnitTest]
    public function testGetSendsTheCompiledStatementAndReturnsRowsKeyedByColumn(): void
    {
        $connection = $this->connection([self::seriesResponse([
            self::series('cpu', ['time', 'usage_user', 'host'], [
                ['2024-01-01T00:00:00Z', 0.64, 'web1'],
                ['2024-01-01T00:10:00Z', 0.42, 'web1'],
            ]),
        ])]);

        $rows = $connection->table('cpu')->select('usage_user', 'host')->where('host', 'web1')->get();

        $this->assertInstanceOf(Collection::class, $rows);
        $this->assertContainsOnlyInstancesOf(stdClass::class, $rows);
        $this->assertSame([
            ['time' => '2024-01-01T00:00:00Z', 'usage_user' => 0.64, 'host' => 'web1'],
            ['time' => '2024-01-01T00:10:00Z', 'usage_user' => 0.42, 'host' => 'web1'],
        ], $this->arrays($rows));
        $this->assertSame('SELECT "usage_user", "host" FROM "cpu" WHERE "host" = \'web1\'', $this->lastStatement());
        $this->assertSame(['db' => 'main-bucket'], $this->lastQueryParameters());
    }

    #[UnitTest]
    public function testGetFlattensEverySeriesAndAppendsItsGroupByTags(): void
    {
        $connection = $this->connection([self::seriesResponse([
            self::series('cpu', ['time', 'mean'], [['2024-01-01T00:00:00Z', 0.5]], ['host' => 'web1']),
            self::series('cpu', ['time', 'mean'], [['2024-01-01T00:00:00Z', 0.7]], ['host' => 'web2']),
        ])]);

        $rows = $connection->table('cpu')->selectRaw('MEAN("usage_user") AS "mean"')->groupBy('host')->get();

        $this->assertSame([
            ['time' => '2024-01-01T00:00:00Z', 'mean' => 0.5, 'host' => 'web1'],
            ['time' => '2024-01-01T00:00:00Z', 'mean' => 0.7, 'host' => 'web2'],
        ], $this->arrays($rows));
        $this->assertSame('SELECT MEAN("usage_user") AS "mean" FROM "cpu" GROUP BY "host"', $this->lastStatement());
    }

    #[UnitTest]
    public function testColumnsPassedToGetAreSelectedForThatCallOnly(): void
    {
        $query = $this->connection([self::emptyResponse(), self::emptyResponse(), self::emptyResponse()])->table('cpu');

        $query->get(['usage_user', 'host']);
        $query->get('usage_system');
        $query->get();

        $this->assertSame([
            'SELECT "usage_user", "host" FROM "cpu"',
            'SELECT "usage_system" FROM "cpu"',
            'SELECT * FROM "cpu"',
        ], $this->statements());
        $this->assertNull($query->columns);
    }

    #[UnitTest]
    public function testColumnsAlreadySelectedWinOverThosePassedToGet(): void
    {
        $this->connection([self::emptyResponse()])->table('cpu')->select('usage_user')->get(['ignored']);

        $this->assertSame('SELECT "usage_user" FROM "cpu"', $this->lastStatement());
    }

    #[UnitTest]
    public function testGetIsEmptyForAStatementWithNoPointsOrAResponseWithNoResults(): void
    {
        $connection = $this->connection([self::emptyResponse(), self::response([])]);

        $this->assertTrue($connection->table('cpu')->get()->isEmpty());
        $this->assertTrue($connection->table('cpu')->get()->isEmpty());
    }

    #[UnitTest]
    public function testSeriesKeepsTheSeriesApart(): void
    {
        $connection = $this->connection([self::seriesResponse([
            self::series('cpu', ['time', 'mean'], [['2024-01-01T00:00:00Z', 0.5]], ['host' => 'web1']),
            self::series('cpu', ['time', 'mean'], [['2024-01-01T00:00:00Z', 0.7]], ['host' => 'web2']),
        ])]);

        $series = $connection->table('cpu')->selectRaw('MEAN("usage_user") AS "mean"')->groupBy('host')->series();

        $this->assertCount(2, $series);
        $this->assertContainsOnlyInstancesOf(Series::class, $series);
        $this->assertSame(['host' => 'web2'], $series[1]->tags);
        $this->assertSame([['2024-01-01T00:00:00Z', 0.7]], $series[1]->values);
        $this->assertSame('SELECT MEAN("usage_user") AS "mean" FROM "cpu" GROUP BY "host"', $this->lastStatement());
    }

    #[UnitTest]
    public function testSeriesIsEmptyForAResponseWithNoResults(): void
    {
        $this->assertSame([], $this->connection([self::response([])])->table('cpu')->series());
    }

    #[UnitTest]
    public function testFirstLimitsTheQueryToOnePoint(): void
    {
        $connection = $this->connection([
            self::seriesResponse([self::series('cpu', ['time', 'usage_user'], [['2024-01-01T00:10:00Z', 0.42]])]),
            self::emptyResponse(),
        ]);

        $this->assertSame(['time' => '2024-01-01T00:10:00Z', 'usage_user' => 0.42], (array) $connection->table('cpu')->latest()->first(['usage_user']));
        $this->assertSame('SELECT "usage_user" FROM "cpu" ORDER BY "time" DESC LIMIT 1', $this->lastStatement());
        $this->assertNull($connection->table('cpu')->first());
    }

    #[UnitTest]
    public function testValueReturnsTheNamedColumnOfTheFirstPoint(): void
    {
        $connection = $this->connection([self::seriesResponse([self::series('cpu', ['time', 'usage_user'], [['2024-01-01T00:00:00Z', 0.64]])])]);

        $this->assertSame(0.64, $connection->table('cpu')->value('usage_user'));
        $this->assertSame('SELECT "usage_user" FROM "cpu" LIMIT 1', $this->lastStatement());
    }

    #[UnitTest]
    public function testValueReadsTheFirstColumnAfterTimeForAnAliasOrAnExpression(): void
    {
        $connection = $this->connection([
            self::seriesResponse([self::series('cpu', ['time', 'user'], [['2024-01-01T00:00:00Z', 0.64]])]),
            self::seriesResponse([self::series('cpu', ['time', 'mean'], [['1970-01-01T00:00:00Z', 0.53]])]),
        ]);

        $this->assertSame(0.64, $connection->table('cpu')->value('usage_user as user'));
        $this->assertSame(0.53, $connection->table('cpu')->value(new Expression('MEAN("usage_user")')));
        $this->assertSame('SELECT MEAN("usage_user") FROM "cpu" LIMIT 1', $this->lastStatement());
    }

    #[UnitTest]
    public function testValueIsNullWithoutAPointOrWithOnlyATimestamp(): void
    {
        $connection = $this->connection([
            self::emptyResponse(),
            self::seriesResponse([self::series('cpu', ['time'], [['2024-01-01T00:00:00Z']])]),
        ]);

        $this->assertNull($connection->table('cpu')->value('usage_user'));
        $this->assertNull($connection->table('cpu')->value(new Expression('MEAN("usage_user")')));
    }

    #[UnitTest]
    public function testPluckReturnsTheValuesOfOneColumn(): void
    {
        $connection = $this->connection([self::seriesResponse([
            self::series('cpu', ['time', 'usage_user'], [['2024-01-01T00:00:00Z', 0.64], ['2024-01-01T00:10:00Z', 0.42]]),
        ])]);

        $this->assertSame([0.64, 0.42], $connection->table('cpu')->pluck('usage_user')->all());
        $this->assertSame('SELECT "usage_user" FROM "cpu"', $this->lastStatement());
    }

    #[UnitTest]
    public function testPluckKeysTheValuesByAnotherColumn(): void
    {
        $connection = $this->connection([self::seriesResponse([
            self::series('cpu', ['time', 'usage_user', 'host'], [['2024-01-01T00:00:00Z', 0.64, 'web1'], ['2024-01-01T00:00:00Z', 0.42, 'web2']]),
        ])]);

        $this->assertSame(['web1' => 0.64, 'web2' => 0.42], $connection->table('cpu')->pluck('usage_user', 'host')->all());
        $this->assertSame('SELECT "usage_user", "host" FROM "cpu"', $this->lastStatement());
    }

    #[UnitTest]
    public function testPluckFindsAnAliasedColumnAndATypeHintedKeyUnderTheirReturnedNames(): void
    {
        $connection = $this->connection([self::seriesResponse([
            self::series('cpu', ['time', 'user', 'host'], [['2024-01-01T00:00:00Z', 0.64, 'web1'], ['2024-01-01T00:00:00Z', 0.42, 'web2']]),
        ])]);

        $this->assertSame(['web1' => 0.64, 'web2' => 0.42], $connection->table('cpu')->pluck('usage_user as user', 'host::tag')->all());
        $this->assertSame('SELECT "usage_user" AS "user", "host"::tag FROM "cpu"', $this->lastStatement());
    }

    #[UnitTest]
    public function testPluckReadsAnExpressionFromTheFirstColumnAfterTime(): void
    {
        $connection = $this->connection([
            self::seriesResponse([self::series('cpu', ['time', 'mean'], [['2024-01-01T00:00:00Z', 0.5], ['2024-01-01T01:00:00Z', 0.7]])]),
            self::seriesResponse([self::series('cpu', ['time'], [['2024-01-01T00:00:00Z']])]),
        ]);

        $this->assertSame([0.5, 0.7], $connection->table('cpu')->groupByTime('1h')->pluck(new Expression('MEAN("usage_user")'))->all());
        $this->assertSame(['2024-01-01T00:00:00Z'], $connection->table('cpu')->pluck(new Expression('MEAN("usage_user")'))->all());
    }

    #[UnitTest]
    public function testPluckSelectsAKeyEqualToTheColumnOnce(): void
    {
        $connection = $this->connection([self::seriesResponse([self::series('cpu', ['time', 'host'], [['2024-01-01T00:00:00Z', 'web1']])])]);

        $this->assertSame(['web1' => 'web1'], $connection->table('cpu')->pluck('host', 'host')->all());
        $this->assertSame('SELECT "host" FROM "cpu"', $this->lastStatement());
    }

    #[UnitTest]
    public function testPluckGivesNullForAColumnTheRowsLack(): void
    {
        $connection = $this->connection([self::seriesResponse([self::series('cpu', ['time', 'usage_user'], [['2024-01-01T00:00:00Z', 0.64]])])]);

        $this->assertSame([null], $connection->table('cpu')->pluck('usage_system')->all());
    }

    #[UnitTest]
    public function testPluckIsEmptyWithoutPoints(): void
    {
        $this->assertTrue($this->connection([self::emptyResponse()])->table('cpu')->pluck('usage_user')->isEmpty());
    }

    #[UnitTest]
    public function testExistsAsksForOnePointOnAClone(): void
    {
        $query = $this->connection([
            self::seriesResponse([self::series('cpu', ['time', 'value'], [['2024-01-01T00:00:00Z', 1]])]),
            self::emptyResponse(),
        ])->table('cpu')->where('host', 'web1');

        $this->assertTrue($query->exists());
        $this->assertSame('SELECT * FROM "cpu" WHERE "host" = \'web1\' LIMIT 1', $this->lastStatement());
        $this->assertNull($query->getLimit());
        $this->assertTrue($query->doesntExist());
    }

    #[UnitTest]
    public function testExistsOrAndDoesntExistOrRunTheCallbackOnlyWhenTheyFail(): void
    {
        $point = fn (): Response => self::seriesResponse([self::series('cpu', ['time', 'value'], [['2024-01-01T00:00:00Z', 1]])]);

        $connection = $this->connection([$point(), self::emptyResponse(), self::emptyResponse(), $point()]);

        $this->assertTrue($connection->table('cpu')->existsOr(fn (): string => 'missing'));
        $this->assertSame('missing', $connection->table('cpu')->existsOr(fn (): string => 'missing'));
        $this->assertTrue($connection->table('cpu')->doesntExistOr(fn (): string => 'present'));
        $this->assertSame('present', $connection->table('cpu')->doesntExistOr(fn (): string => 'present'));
    }

    #[UnitTest]
    public function testCountAliasesTheAggregateAndReturnsAnInteger(): void
    {
        $connection = $this->connection([self::seriesResponse([self::series('cpu', ['time', 'aggregate'], [['1970-01-01T00:00:00Z', 42]])])]);

        $this->assertSame(42, $connection->table('cpu')->where('host', 'web1')->count('usage_user'));
        $this->assertSame('SELECT COUNT("usage_user") AS "aggregate" FROM "cpu" WHERE "host" = \'web1\'', $this->lastStatement());
    }

    #[UnitTest]
    public function testCountingEveryFieldReturnsTheFirstFieldsCount(): void
    {
        $connection = $this->connection([self::seriesResponse([
            self::series('cpu', ['time', 'count_usage_system', 'count_usage_user'], [['1970-01-01T00:00:00Z', 10, 12]]),
        ])]);

        $this->assertSame(10, $connection->table('cpu')->count());
        $this->assertSame('SELECT COUNT(*) FROM "cpu"', $this->lastStatement());
    }

    #[UnitTest]
    #[DataProvider('aggregates')]
    public function testEachAggregateRunsItsInfluxqlFunction(string $method, string $function, float|int $result): void
    {
        $connection = $this->connection([self::seriesResponse([self::series('cpu', ['time', 'aggregate'], [['1970-01-01T00:00:00Z', $result]])])]);

        $this->assertSame($result, $connection->table('cpu')->{$method}('usage_user'));
        $this->assertSame("SELECT {$function}(\"usage_user\") AS \"aggregate\" FROM \"cpu\"", $this->lastStatement());
    }

    /**
     * @return array<string, array{string, string, float|int}>
     */
    public static function aggregates(): array
    {
        return [
            'min' => ['min', 'MIN', 0.01],
            'max' => ['max', 'MAX', 0.99],
            'sum' => ['sum', 'SUM', 12.5],
            'avg' => ['avg', 'MEAN', 0.5],
            'average' => ['average', 'MEAN', 0.5],
        ];
    }

    #[UnitTest]
    public function testAnAggregateOfNoPointsIsNullButACountOrSumOfThemIsZero(): void
    {
        $connection = $this->connection([self::emptyResponse(), self::emptyResponse(), self::emptyResponse()]);

        $this->assertNull($connection->table('cpu')->max('usage_user'));
        $this->assertSame(0, $connection->table('cpu')->count('usage_user'));
        $this->assertSame(0, $connection->table('cpu')->sum('usage_user'));
    }

    #[UnitTest]
    public function testAnAggregateIsNullWhenOnlyATimestampCameBack(): void
    {
        $connection = $this->connection([self::seriesResponse([self::series('cpu', ['time'], [['1970-01-01T00:00:00Z']])])]);

        $this->assertNull($connection->table('cpu')->aggregate('count'));
    }

    #[UnitTest]
    public function testAggregateTakesAnyInfluxqlFunctionAndItsArguments(): void
    {
        $connection = $this->connection([self::seriesResponse([self::series('cpu', ['time', 'aggregate'], [['1970-01-01T00:00:00Z', 0.97]])])]);

        $this->assertSame(0.97, $connection->table('cpu')->aggregate('percentile', ['usage_user', new Expression('95')]));
        $this->assertSame('SELECT PERCENTILE("usage_user", 95) AS "aggregate" FROM "cpu"', $this->lastStatement());
    }

    #[UnitTest]
    public function testAnAggregateDropsTheSelectedColumnsTheirBindingsAndTheOrderOnAClone(): void
    {
        $query = $this->connection([self::seriesResponse([self::series('cpu', ['time', 'aggregate'], [['1970-01-01T00:00:00Z', 3]])])])
            ->table('cpu')
            ->selectRaw('"usage_user" * ? AS "scaled"', [2])
            ->where('host', 'web1')
            ->orderByDesc();

        $query->count('usage_user');

        $this->assertSame('SELECT COUNT("usage_user") AS "aggregate" FROM "cpu" WHERE "host" = \'web1\'', $this->lastStatement());
        $this->assertSame('SELECT "usage_user" * ? AS "scaled" FROM "cpu" WHERE "host" = ? ORDER BY "time" DESC', $query->toSql());
        $this->assertSame([2, 'web1'], $query->getBindings());
    }

    #[UnitTest]
    public function testAGroupedAggregateKeepsTheOrder(): void
    {
        $connection = $this->connection([self::seriesResponse([self::series('cpu', ['time', 'aggregate'], [['2024-01-01T01:00:00Z', 0.9]])])]);

        $connection->table('cpu')->groupByTime('1h')->orderByDesc()->max('usage_user');

        $this->assertSame('SELECT MAX("usage_user") AS "aggregate" FROM "cpu" GROUP BY time(1h) ORDER BY "time" DESC', $this->lastStatement());
    }

    #[UnitTest]
    #[DataProvider('numericAggregates')]
    public function testANumericAggregateIsCastToANumber(mixed $result, float|int $expected): void
    {
        $response = $result === null
            ? self::emptyResponse()
            : self::seriesResponse([self::series('cpu', ['time', 'aggregate'], [['1970-01-01T00:00:00Z', $result]])]);

        $this->assertSame($expected, $this->connection([$response])->table('cpu')->numericAggregate('last', ['usage_user']));
    }

    /**
     * @return array<string, array{mixed, float|int}>
     */
    public static function numericAggregates(): array
    {
        return [
            'integer' => [5, 5],
            'float' => [2.5, 2.5],
            'integer string' => ['7', 7],
            'decimal string' => ['7.5', 7.5],
            'zero' => [0, 0],
            'no points' => [null, 0],
        ];
    }

    #[UnitTest]
    public function testChunkPagesThroughThePointsWithLimitAndOffset(): void
    {
        $connection = $this->connection([
            self::seriesResponse([self::series('cpu', ['time', 'value'], [['t1', 1], ['t2', 2]])]),
            self::seriesResponse([self::series('cpu', ['time', 'value'], [['t3', 3], ['t4', 4]])]),
            self::seriesResponse([self::series('cpu', ['time', 'value'], [['t5', 5]])]),
        ]);

        $pages = [];

        $this->assertTrue($connection->table('cpu')->chunk(2, function (Collection $rows, int $page) use (&$pages): void {
            $pages[$page] = $rows->pluck('value')->all();
        }));
        $this->assertSame([1 => [1, 2], 2 => [3, 4], 3 => [5]], $pages);
        $this->assertSame([
            'SELECT * FROM "cpu" LIMIT 2 OFFSET 0',
            'SELECT * FROM "cpu" LIMIT 2 OFFSET 2',
            'SELECT * FROM "cpu" LIMIT 2 OFFSET 4',
        ], $this->statements());
    }

    #[UnitTest]
    public function testChunkStopsAtAnEmptyPage(): void
    {
        $connection = $this->connection([
            self::seriesResponse([self::series('cpu', ['time', 'value'], [['t1', 1], ['t2', 2]])]),
            self::emptyResponse(),
        ]);

        $pages = 0;

        $this->assertTrue($connection->table('cpu')->chunk(2, function () use (&$pages): void {
            ++$pages;
        }));
        $this->assertSame(1, $pages);
        $this->assertCount(2, $this->queries());
    }

    #[UnitTest]
    public function testChunkStopsWhenTheCallbackReturnsFalse(): void
    {
        $connection = $this->connection([self::seriesResponse([self::series('cpu', ['time', 'value'], [['t1', 1], ['t2', 2]])])]);

        $this->assertFalse($connection->table('cpu')->chunk(2, fn (): bool => false));
        $this->assertCount(1, $this->queries());
    }

    #[UnitTest]
    public function testChunkStaysInsideAnOffsetAndLimitAlreadySet(): void
    {
        $connection = $this->connection([
            self::seriesResponse([self::series('cpu', ['time', 'value'], [['t1', 1], ['t2', 2]])]),
            self::seriesResponse([self::series('cpu', ['time', 'value'], [['t3', 3]])]),
        ]);

        $connection->table('cpu')->offset(10)->limit(3)->chunk(2, fn (): null => null);

        $this->assertSame(['SELECT * FROM "cpu" LIMIT 2 OFFSET 10', 'SELECT * FROM "cpu" LIMIT 1 OFFSET 12'], $this->statements());
    }

    #[UnitTest]
    public function testChunkSendsNothingOnceTheLimitIsUsedUp(): void
    {
        $connection = $this->connection([
            self::seriesResponse([self::series('cpu', ['time', 'value'], [['t1', 1], ['t2', 2]])]),
            self::seriesResponse([self::series('cpu', ['time', 'value'], [['t3', 3], ['t4', 4]])]),
        ]);

        $this->assertTrue($connection->table('cpu')->limit(4)->chunk(2, fn (): null => null));
        $this->assertSame(['SELECT * FROM "cpu" LIMIT 2 OFFSET 0', 'SELECT * FROM "cpu" LIMIT 2 OFFSET 2'], $this->statements());
    }

    #[UnitTest]
    public function testChunkRefusesASizeBelowOne(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('The chunk size should be at least 1');

        $this->connection()->table('cpu')->chunk(0, fn (): null => null);
    }

    #[UnitTest]
    public function testDeleteSendsADeleteStatementForTheWhereClause(): void
    {
        $connection = $this->connection([self::emptyResponse()]);

        $deleted = $connection->table('cpu')
            ->where('host', 'web1')
            ->where('time', '<', new DateTimeImmutable('2024-01-01T00:00:00Z'))
            ->delete();

        $this->assertTrue($deleted);
        $this->assertSame('POST', $this->lastRequest()->getMethod());
        $this->assertSame('DELETE FROM "cpu" WHERE "host" = \'web1\' AND "time" < \'2024-01-01T00:00:00.000000Z\'', $this->lastStatement());
    }

    #[UnitTest]
    public function testDeleteFromARegexEmbedsThePattern(): void
    {
        $this->connection([self::emptyResponse()])->query()->from(new Regex('^cpu'))->where('host', 'web1')->delete();

        $this->assertSame('DELETE FROM /^cpu/ WHERE "host" = \'web1\'', $this->lastStatement());
    }

    #[UnitTest]
    public function testDeleteOnAV2ConnectionThatAddressesNoRetentionPolicyIsSentAsOnV1(): void
    {
        $this->pingVersion = 'v2.7.12';

        $deleted = $this->connection([self::noResultsResponse()], ['version' => 'v2', 'bucket' => 'telegraf'])
            ->table('cpu')
            ->where('host', 'web1')
            ->where('time', '<', new DateTimeImmutable('2024-01-01T00:00:00Z'))
            ->delete();

        $this->assertTrue($deleted);
        $this->assertSame('DELETE FROM "cpu" WHERE "host" = \'web1\' AND "time" < \'2024-01-01T00:00:00.000000Z\'', $this->lastStatement());
        $this->assertSame(['db' => 'telegraf'], $this->lastQueryParameters());
    }

    #[UnitTest]
    #[DataProvider('statementsAV2ConnectionRefuses')]
    public function testAStatementAV2ConnectionCannotRunFailsBeforeAnythingIsSent(Closure $send, string $message): void
    {
        $this->pingVersion = null;

        $query = $this->connection([self::pingResponse('v2.7.12'), self::emptyResponse()], ['version' => 'v2', 'bucket' => 'telegraf/weekly'])->table('cpu');

        try {
            $send($query);

            $this->fail('No exception was thrown.');
        } catch (RuntimeException $exception) {
            $this->assertNotInstanceOf(QueryException::class, $exception);
            $this->assertSame($message, $exception->getMessage());
            $this->assertSame([], $this->history);
        }
    }

    /**
     * @return array<string, array{Closure(Builder): mixed, string}>
     */
    public static function statementsAV2ConnectionRefuses(): array
    {
        return [
            'select into' => [
                static fn (Builder $query): mixed => $query->selectRaw('MEAN("value")')->into('cpu_hourly')->groupByTime('1h')->get(),
                'InfluxDB 2.x does not support SELECT ... INTO; downsample with a task instead.',
            ],
            'delete from a retention policy' => [
                static fn (Builder $query): mixed => $query->where('host', 'web1')->delete(),
                'InfluxDB 2.x deletes only from the database\'s default retention policy, not from the connection\'s [weekly]; delete through the /api/v2/delete API instead.',
            ],
        ];
    }

    #[UnitTest]
    public function testAV3ConnectionReadsTheWholeBucketWithoutARetentionPolicy(): void
    {
        $this->pingVersion = '3.11.5';

        $rows = $this->connection([self::seriesResponse([self::series('cpu', ['time', 'usage_user'], [['2024-01-01T00:00:00Z', 0.64]])])], ['version' => 'v3', 'bucket' => 'telegraf/autogen'])
            ->table('cpu')
            ->select('usage_user')
            ->where('host', 'web1')
            ->soffset(null)
            ->get();

        $this->assertSame([['time' => '2024-01-01T00:00:00Z', 'usage_user' => 0.64]], $this->arrays($rows));
        $this->assertSame('SELECT "usage_user" FROM "cpu" WHERE "host" = \'web1\'', $this->lastStatement());
        $this->assertSame(['db' => 'telegraf/autogen'], $this->lastQueryParameters());
    }

    #[UnitTest]
    #[DataProvider('statementsAV3ConnectionRefuses')]
    public function testAStatementAV3ConnectionCannotRunFailsBeforeAnythingIsSent(Closure $send, string $message): void
    {
        $this->pingVersion = null;

        $query = $this->connection([self::pingResponse('3.11.5'), self::emptyResponse()], ['version' => 'v3', 'bucket' => 'telegraf'])->table('cpu');

        try {
            $send($query);

            $this->fail('No exception was thrown.');
        } catch (RuntimeException $exception) {
            $this->assertNotInstanceOf(QueryException::class, $exception);
            $this->assertSame($message, $exception->getMessage());
            $this->assertSame([], $this->history);
        }
    }

    /**
     * @return array<string, array{Closure(Builder): mixed, string}>
     */
    public static function statementsAV3ConnectionRefuses(): array
    {
        return [
            'select into' => [
                static fn (Builder $query): mixed => $query->selectRaw('MEAN("value")')->into('cpu_hourly')->groupByTime('1h')->get(),
                'InfluxDB 3 does not support SELECT ... INTO; downsample with the processing engine instead.',
            ],
            'slimit' => [
                static fn (Builder $query): mixed => $query->groupBy('*')->slimit(1)->get(),
                'InfluxDB 3 does not support SLIMIT.',
            ],
            'soffset' => [
                static fn (Builder $query): mixed => $query->groupBy('*')->soffset(1)->series(),
                'InfluxDB 3 does not support SOFFSET.',
            ],
            'delete' => [
                static fn (Builder $query): mixed => $query->where('host', 'web1')->delete(),
                'InfluxDB 3 does not support DELETE; delete the table or the database instead.',
            ],
        ];
    }

    #[UnitTest]
    public function testAStatementTheServerRefusesIsAQueryException(): void
    {
        $query = $this->connection([self::errorResponse('measurement not found')])->table('cpu')->where('host', 'web1');

        try {
            $query->get();

            $this->fail('No exception was thrown.');
        } catch (QueryException $exception) {
            $this->assertSame('measurement not found (Connection: main, InfluxQL: SELECT * FROM "cpu" WHERE "host" = \'web1\')', $exception->getMessage());
            $this->assertSame('main', $exception->getConnectionName());
            $this->assertSame('SELECT * FROM "cpu" WHERE "host" = \'web1\'', $exception->getSql());
            $this->assertSame(['web1'], $exception->getBindings());
            $this->assertNull($exception->getPrevious());
        }
    }

    #[UnitTest]
    public function testAnErrorStatusIsAQueryExceptionChainingTheApiException(): void
    {
        $query = $this->connection([new Response(500, [], '{"error":"internal error"}')])->table('cpu');

        try {
            $query->get();

            $this->fail('No exception was thrown.');
        } catch (QueryException $exception) {
            $this->assertInstanceOf(ApiException::class, $exception->getPrevious());
            $this->assertSame(500, $exception->getCode());
            $this->assertStringContainsString('internal error', $exception->getMessage());
            $this->assertStringEndsWith('(Connection: main, InfluxQL: SELECT * FROM "cpu")', $exception->getMessage());
        }
    }

    #[UnitTest]
    public function testAnUnreachableServerIsAQueryException(): void
    {
        $query = $this->connection([new ConnectException('Connection refused', new Request('POST', '/query'))])->table('cpu');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageIsOrContains('Connection refused');

        $query->get();
    }

    #[UnitTest]
    public function testAValueWithoutALiteralFailsBeforeAnythingIsSent(): void
    {
        $query = $this->connection([self::emptyResponse()])->table('cpu')->whereIn('host', ['web1', null]);

        try {
            $query->get();

            $this->fail('No exception was thrown.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('InfluxQL has no null literal; a field or tag cannot be compared with null.', $exception->getMessage());
            $this->assertSame([], $this->history);
        }
    }

    #[UnitTest]
    public function testTheServersVersionIsReadOnceBeforeTheFirstStatement(): void
    {
        $connection = $this->connection([self::emptyResponse(), self::emptyResponse()]);

        $connection->table('cpu')->get();
        $connection->table('mem')->delete();

        $this->assertSame(['GET /ping', 'POST /query', 'POST /query'], $this->requestLines());
    }

    #[UnitTest]
    #[DataProvider('serversOfTheConnectionsVersion')]
    public function testTheBuilderRunsOnAServerOfTheConnectionsVersion(string $connection, string $server): void
    {
        $this->pingVersion = $server;

        $this->assertSame([], $this->connection([self::emptyResponse()], ['version' => $connection])->table('cpu')->get()->all());
        $this->assertSame(['SELECT * FROM "cpu"'], $this->statements());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function serversOfTheConnectionsVersion(): array
    {
        return [
            '1.x: a release' => ['v1', '1.8.10'],
            '1.x: a two-digit minor' => ['v1', '1.11.8'],
            '1.x: a suffixed build' => ['v1', '1.8.10-c1.8.10'],
            '1.x: a v prefix' => ['v1', 'v1.8.10'],
            '1.x: a bare major' => ['v1', '1'],
            '2.x: a release' => ['v2', 'v2.7.12'],
            '2.x: a pre-release' => ['v2', 'v2.0.0-beta.16'],
            '2.x: no v prefix' => ['v2', '2.7.12'],
            '2.x: a bare major' => ['v2', 'v2'],
            '3: a release' => ['v3', '3.11.5'],
            '3: a v prefix' => ['v3', 'v3.0.0'],
            '3: a bare major' => ['v3', '3'],
        ];
    }

    #[UnitTest]
    #[DataProvider('serversOfAnotherVersion')]
    public function testTheBuilderRefusesAServerThatRunsAnotherVersionThanTheConnection(string $connection, ?string $server, string $expected): void
    {
        $this->pingVersion = null;

        $query = $this->connection([self::pingResponse($server), self::emptyResponse()], ['version' => $connection])->table('cpu')->where('host', 'web1');

        try {
            $query->get();

            $this->fail('No exception was thrown.');
        } catch (QueryException $exception) {
            $this->assertSame(
                "The InfluxQL builder compiles for {$expected}, but the server reports "
                . ($server === null ? 'no version' : "version [{$server}]")
                . ". (Connection: main, InfluxQL: SELECT * FROM \"cpu\" WHERE \"host\" = 'web1')",
                $exception->getMessage(),
            );
            $this->assertSame(['GET /ping'], $this->requestLines());
        }
    }

    /**
     * @return array<string, array{string, ?string, string}>
     */
    public static function serversOfAnotherVersion(): array
    {
        $v1 = 'InfluxDB 1.x on this connection (version v1)';
        $v2 = 'InfluxDB 2.x on this connection (version v2)';
        $v3 = 'InfluxDB 3 on this connection (version v3)';

        return [
            '1.x connection: InfluxDB 2.x' => ['v1', 'v2.7.12', $v1],
            '1.x connection: InfluxDB 3' => ['v1', '3.11.5', $v1],
            '1.x connection: a major that starts with 1' => ['v1', '10.0.0', $v1],
            '1.x connection: no version number' => ['v1', 'unknown', $v1],
            '1.x connection: no version header' => ['v1', null, $v1],
            '2.x connection: InfluxDB 1.x' => ['v2', '1.8.10', $v2],
            '2.x connection: InfluxDB 1.x with a v prefix' => ['v2', 'v1.8.10', $v2],
            '2.x connection: InfluxDB 3' => ['v2', '3.11.5', $v2],
            '2.x connection: a major that starts with 2' => ['v2', 'v20.1.0', $v2],
            '2.x connection: no version header' => ['v2', null, $v2],
            '3 connection: InfluxDB 1.x' => ['v3', '1.8.10', $v3],
            '3 connection: InfluxDB 2.x' => ['v3', 'v2.7.12', $v3],
            '3 connection: a major that starts with 3' => ['v3', '30.1.0', $v3],
            '3 connection: no version header' => ['v3', null, $v3],
        ];
    }

    #[UnitTest]
    #[DataProvider('sendingMethods')]
    public function testEveryStatementTheBuilderSendsIsCheckedFirst(Closure $send, string $statement): void
    {
        $this->pingVersion = 'v2.7.12';

        $query = $this->connection([self::emptyResponse()])->table('cpu')->where('host', 'web1');

        try {
            $send($query);

            $this->fail('No exception was thrown.');
        } catch (QueryException $exception) {
            $this->assertSame($statement, $exception->getSql());
            $this->assertSame(['web1'], $exception->getBindings());
            $this->assertSame(['GET /ping'], $this->requestLines());
        }
    }

    /**
     * @return array<string, array{Closure(Builder): mixed, string}>
     */
    public static function sendingMethods(): array
    {
        return [
            'get' => [static fn (Builder $query): mixed => $query->get(), 'SELECT * FROM "cpu" WHERE "host" = \'web1\''],
            'series' => [static fn (Builder $query): mixed => $query->series(), 'SELECT * FROM "cpu" WHERE "host" = \'web1\''],
            'delete' => [static fn (Builder $query): mixed => $query->delete(), 'DELETE FROM "cpu" WHERE "host" = \'web1\''],
        ];
    }

    #[UnitTest]
    public function testAPingThatFailsIsAQueryExceptionAndIsTriedAgain(): void
    {
        $this->pingVersion = null;

        $connection = $this->connection([new Response(503, [], 'unavailable'), self::pingResponse(), self::emptyResponse()]);

        try {
            $connection->table('cpu')->get();

            $this->fail('No exception was thrown.');
        } catch (QueryException $exception) {
            $this->assertInstanceOf(ApiException::class, $exception->getPrevious());
            $this->assertSame(503, $exception->getCode());
            $this->assertSame('SELECT * FROM "cpu"', $exception->getSql());
        }

        $this->assertSame([], $connection->table('cpu')->get()->all());
        $this->assertSame(['GET /ping', 'GET /ping', 'POST /query'], $this->requestLines());
    }

    #[UnitTest]
    public function testTheBuilderEmbedsTheValuesItselfOnlyBeforeTheServerHasNamedItsVersion(): void
    {
        $grammar = new class extends V1Grammar {
            public int $embeddings = 0;

            public function substituteBindingsIntoRawSql(string $sql, array $bindings): string
            {
                ++$this->embeddings;

                return parent::substituteBindingsIntoRawSql($sql, $bindings);
            }
        };

        $client = $this->client([self::pingResponse(), self::emptyResponse(), self::emptyResponse()]);
        $connection = new V1Connection($client, ['name' => 'main', 'bucket' => 'main-bucket'], null, $grammar);

        $connection->table('cpu')->where('host', 'web1')->get();

        $this->assertSame(2, $grammar->embeddings, 'Before the /ping, and again to send the statement.');

        $connection->table('cpu')->where('host', 'web1')->get();

        $this->assertSame(3, $grammar->embeddings, 'Only to send the statement.');
        $this->assertSame(['GET /ping', 'POST /query', 'POST /query'], $this->requestLines());
    }

    #[UnitTest]
    public function testAValueWithoutALiteralStillFailsBeforeAnythingIsSentOnceTheVersionIsKnown(): void
    {
        $connection = $this->connection([self::emptyResponse(), self::emptyResponse()]);

        $connection->table('cpu')->get();

        try {
            $connection->table('cpu')->whereIn('host', ['web1', null])->get();

            $this->fail('No exception was thrown.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('InfluxQL has no null literal; a field or tag cannot be compared with null.', $exception->getMessage());
            $this->assertSame(['GET /ping', 'POST /query'], $this->requestLines());
        }
    }

    #[UnitTest]
    public function testAServerFoundToRunAnotherVersionIsNotAskedAgain(): void
    {
        $this->pingVersion = 'v2.7.12';

        $connection = $this->connection();

        foreach (['cpu', 'mem'] as $measurement) {
            try {
                $connection->table($measurement)->where('host', 'web1')->get();

                $this->fail('No exception was thrown.');
            } catch (QueryException $exception) {
                $this->assertSame("SELECT * FROM \"{$measurement}\" WHERE \"host\" = 'web1'", $exception->getSql());
            }
        }

        $this->assertSame(['GET /ping'], $this->requestLines());
    }

    #[UnitTest]
    #[DataProvider('refusedWheres')]
    public function testAWhereInfluxqlCannotExpressIsRefused(callable $where, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs($message);

        $where($this->connection()->table('cpu'));
    }

    /**
     * @return array<string, array{callable(Builder): mixed, string}>
     */
    public static function refusedWheres(): array
    {
        $null = 'InfluxQL has no null literal; a field or tag cannot be compared with null.';
        $operator = '[%s] is not an InfluxQL operator; use one of =, <, >, <=, >=, <>, !=, =~, !~.';

        return [
            'a null value' => [fn (Builder $query) => $query->where('host', null), $null],
            'a null value with equality' => [fn (Builder $query) => $query->where('host', '=', null), $null],
            'a null value with an ordering operator' => [fn (Builder $query) => $query->where('value', '>', null), 'Illegal operator and value combination.'],
            'a null or-where with an ordering operator' => [fn (Builder $query) => $query->orWhere('value', '>', null), 'Illegal operator and value combination.'],
            'an operator for a closure' => [fn (Builder $query) => $query->where(fn (Builder $query) => $query, '=', 1), 'InfluxQL cannot compare a sub-select; a Closure column starts a nested where and takes no operator or value.'],
            'a closure value' => [fn (Builder $query) => $query->where('value', '=', fn (Builder $query) => $query), 'InfluxQL cannot compare a column with a sub-select.'],
            'a builder value' => [fn (Builder $query) => $query->where('value', $query->newQuery()), 'InfluxQL cannot compare a column with a sub-select.'],
            'an SQL operator' => [fn (Builder $query) => $query->where('host', 'like', 'web%'), sprintf($operator, 'like')],
            'a non-string operator' => [fn (Builder $query) => $query->where('value', 5, 6), sprintf($operator, 'int')],
            'an SQL operator between columns' => [fn (Builder $query) => $query->whereColumn('host', 'like', 'region'), sprintf($operator, 'like')],
            'a regex operator with a number' => [fn (Builder $query) => $query->where('host', '=~', 5), 'The =~ operator takes a regular expression, as a string or a Regex.'],
            'a regex with an ordering operator' => [fn (Builder $query) => $query->where('host', '>', new Regex('^web')), 'A regular expression is matched with =~ or !~, not >.'],
            'a nested where in list' => [fn (Builder $query) => $query->whereIn('host', [['web1', 'web2']]), 'Nested arrays may not be passed to whereIn method.'],
            'a between with one bound' => [fn (Builder $query) => $query->whereBetween('value', [1]), 'whereBetween needs a lower and an upper bound.'],
        ];
    }

    #[UnitTest]
    public function testAnInvalidOperatorWithoutAValueIsTheValueItself(): void
    {
        $this->assertSame(
            'SELECT * FROM "cpu" WHERE "value" > 5 OR "host" = \'web1\'',
            $this->connection()->table('cpu')->where('value', '>', 5)->where('host', 'web1', null, 'or')->toRawSql(),
        );
        $this->assertSame(
            'SELECT * FROM "cpu" WHERE "a" = "b"',
            $this->connection()->table('cpu')->whereColumn('a', 'b', null)->toSql(),
        );
    }

    #[UnitTest]
    public function testAnArrayValueComparesItsFirstElement(): void
    {
        $this->assertSame('SELECT * FROM "cpu" WHERE "host" = \'web1\'', $this->connection()->table('cpu')->where('host', [['web1'], 'web2'])->toRawSql());
    }

    #[UnitTest]
    public function testWhereInTakesArrayableAndTraversableValues(): void
    {
        $generator = (function () {
            yield 'a' => 'web3';
            yield 'b' => 'web4';
        })();

        $query = $this->connection()->table('cpu')->whereIn('host', new Collection(['web1', 'web2']))->whereNotIn('host', $generator);

        $this->assertSame(['web1', 'web2', 'web3', 'web4'], $query->getBindings());
    }

    #[UnitTest]
    public function testWhereBetweenTakesTheFirstTwoValuesOfAnIterable(): void
    {
        $this->assertSame([1, 2], $this->connection()->table('cpu')->whereBetween('value', new ArrayIterator([1, 2, 3]))->getBindings());
        $this->assertSame([1, 2], $this->connection()->table('cpu')->whereBetween('value', ['low' => 1, 'high' => 2, 'extra' => 3])->getBindings());
    }

    #[UnitTest]
    public function testWhereBetweenTakesADatePeriodsStartAndEnd(): void
    {
        $period = CarbonPeriod::create(CarbonImmutable::parse('2024-01-01T00:00:00Z'), CarbonImmutable::parse('2024-01-31T00:00:00Z'));

        $this->assertSame(
            'SELECT * FROM "cpu" WHERE ("time" >= \'2024-01-01T00:00:00.000000Z\' AND "time" <= \'2024-01-31T00:00:00.000000Z\')',
            $this->connection()->table('cpu')->whereBetween('time', $period)->toRawSql(),
        );
    }

    #[UnitTest]
    public function testWhereBetweenEndsAnOpenDatePeriodAfterItsRecurrences(): void
    {
        $period = new DatePeriod(new DateTimeImmutable('2024-01-01T00:00:00Z'), new DateInterval('P1D'), 3);

        $this->assertSame(
            'SELECT * FROM "cpu" WHERE ("time" >= \'2024-01-01T00:00:00.000000Z\' AND "time" <= \'2024-01-04T00:00:00.000000Z\')',
            $this->connection()->table('cpu')->whereBetween('time', $period)->toRawSql(),
        );
    }

    #[UnitTest]
    public function testADynamicWhereComparesTheSnakeCasedColumn(): void
    {
        $this->assertSame('SELECT * FROM "cpu" WHERE "host" = \'web1\'', $this->connection()->table('cpu')->whereHost('web1')->toRawSql());
        $this->assertSame('SELECT * FROM "cpu" WHERE "cpu_usage" = 5', $this->connection()->table('cpu')->whereCpuUsage(5)->toRawSql());
    }

    #[UnitTest]
    public function testADynamicWhereChainsItsColumnsWithAndAndOr(): void
    {
        $this->assertSame(
            'SELECT * FROM "cpu" WHERE "host" = \'web1\' AND "region" = \'eu\'',
            $this->connection()->table('cpu')->whereHostAndRegion('web1', 'eu')->toRawSql(),
        );
        $this->assertSame(
            'SELECT * FROM "cpu" WHERE "host" = \'web1\' OR "region" = \'eu\'',
            $this->connection()->table('cpu')->whereHostOrRegion('web1', 'eu')->toRawSql(),
        );
    }

    #[UnitTest]
    #[DataProvider('unsupportedWheres')]
    public function testAHypervelWhereClauseThisBuilderLacksFailsLoudly(string $method): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessageIs(sprintf(
            '%s::%s() is not supported: InfluxQL has no NOT, NULL, LIKE, date part, sub-select, JSON or full-text clause, and a relative date is a comparison on time, such as where(\'time\', \'<\', now()).',
            Builder::class,
            $method,
        ));

        $this->connection()->table('cpu')->{$method}('host');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsupportedWheres(): array
    {
        $methods = [
            'whereNull', 'orWhereNull', 'whereNotNull', 'whereNot', 'orWhereNot', 'whereLike', 'whereNotLike',
            'whereDate', 'whereTime', 'whereYear', 'whereExists', 'whereFullText', 'whereJsonContains',
            'wherePast', 'whereFuture', 'whereToday', 'whereBeforeToday', 'whereNowOrPast', 'orWhereTodayOrAfter',
        ];

        return array_combine($methods, array_map(fn (string $method): array => [$method], $methods));
    }

    /**
     * A tripwire for Hypervel adding where-methods of its own.
     *
     * Every where-method of Hypervel's Query\Builder has to be implemented here
     * or refused by name: one that is neither falls through to the dynamic
     * where and compiles to a comparison on a column named after it. CI
     * resolves hypervel/components fresh on every run, so a new upstream
     * method fails this before it reaches an application.
     */
    #[UnitTest]
    public function testEveryWhereMethodOfHypervelsBuilderIsImplementedOrRefused(): void
    {
        $upstream = array_filter(
            array_map(fn (ReflectionMethod $method): string => $method->getName(), (new ReflectionClass(QueryBuilder::class))->getMethods(ReflectionMethod::IS_PUBLIC)),
            fn (string $method): bool => preg_match('/^(?:or)?[wW]here[A-Z]/', $method) === 1,
        );

        $this->assertNotEmpty($upstream, 'Found no where-methods on Hypervel\'s Query\Builder; the scan is broken.');

        $unguarded = [];

        foreach ($upstream as $method) {
            if (method_exists(Builder::class, $method)) {
                continue;
            }

            try {
                $this->connection()->table('cpu')->{$method}('host');

                $unguarded[] = $method;
            } catch (BadMethodCallException) {
                // Refused by name, as it should be.
            } catch (Throwable) {
                // Reached the dynamic where, which then failed on its own.
                $unguarded[] = $method;
            }
        }

        $this->assertSame([], $unguarded, 'These where-methods of Hypervel\'s Query\Builder are neither implemented here nor refused; add them to Builder::UNSUPPORTED_WHERES or implement them.');
    }

    #[UnitTest]
    public function testAnUnknownMethodIsABadMethodCall(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessageIs(sprintf('Call to undefined method %s::frobnicate()', Builder::class));

        $this->connection()->table('cpu')->frobnicate();
    }

    #[UnitTest]
    public function testAMacroIsCallableOnEveryBuilderUntilTheStateIsFlushed(): void
    {
        Builder::macro('onWebHosts', function (): Builder {
            /** @var Builder $this */
            return $this->where('host', '=~', '^web');
        });

        $this->assertTrue(Builder::hasMacro('onWebHosts'));
        $this->assertSame('SELECT * FROM "cpu" WHERE "host" =~ /^web/', $this->connection()->table('cpu')->onWebHosts()->toRawSql());

        Builder::flushState();

        $this->assertFalse(Builder::hasMacro('onWebHosts'));

        $this->expectException(BadMethodCallException::class);

        $this->connection()->table('cpu')->onWebHosts();
    }

    #[UnitTest]
    public function testEnumsAreBoundByValueOrByName(): void
    {
        $query = $this->connection()->table('cpu')
            ->where('region', Region::Europe)
            ->whereIn('region', [Region::Europe, Region::America])
            ->whereNotIn('direction', [SortDirection::Descending]);

        $this->assertSame(['eu', 'eu', 'us', 'Descending'], $query->getBindings());
        $this->assertSame(
            'SELECT * FROM "cpu" WHERE "region" = \'eu\' AND ("region" = \'eu\' OR "region" = \'us\') AND ("direction" != \'Descending\')',
            $query->toRawSql(),
        );
    }

    #[UnitTest]
    public function testSetBindingsReplacesOneTypeAndCastsEnums(): void
    {
        $query = $this->connection()->table('cpu')->where('host', 'web1')->setBindings([Region::America, SortDirection::Ascending, 'x']);

        $this->assertSame(['us', 'Ascending', 'x'], $query->getRawBindings()['where']);
    }

    #[UnitTest]
    public function testAddBindingAppendsAValueOrMergesAList(): void
    {
        $query = $this->connection()->table('cpu')->addBinding('a')->addBinding(['b', Region::Europe])->addBinding(1, 'select');

        $this->assertSame(['select' => [1], 'from' => [], 'where' => ['a', 'b', 'eu'], 'groupBy' => [], 'order' => []], $query->getRawBindings());
        $this->assertSame([1, 'a', 'b', 'eu'], $query->getBindings());
    }

    #[UnitTest]
    #[DataProvider('bindingWriters')]
    public function testAnUnknownBindingTypeIsRefused(string $method): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Invalid binding type: having.');

        $this->connection()->table('cpu')->{$method}([], 'having');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function bindingWriters(): array
    {
        return ['setBindings' => ['setBindings'], 'addBinding' => ['addBinding']];
    }

    #[UnitTest]
    public function testMergeBindingsMergesEachType(): void
    {
        $connection = $this->connection();

        $query = $connection->table('cpu')->where('host', 'web1')->mergeBindings(
            $connection->table('cpu')->selectRaw('?', [1])->where('region', 'eu'),
        );

        $this->assertSame(['select' => [1], 'from' => [], 'where' => ['web1', 'eu'], 'groupBy' => [], 'order' => []], $query->getRawBindings());
    }

    #[UnitTest]
    public function testCleanBindingsDropsExpressionsAndCastsEnums(): void
    {
        $this->assertSame([1, 'eu'], $this->connection()->table('cpu')->cleanBindings(['a' => 1, 'b' => new Expression('now()'), 'c' => Region::Europe]));
        $this->assertSame('Ascending', $this->connection()->table('cpu')->castBinding(SortDirection::Ascending));
        $this->assertSame(5, $this->connection()->table('cpu')->castBinding(5));
    }

    #[UnitTest]
    public function testSelectingAgainDropsTheBindingsOfTheSelectItReplaces(): void
    {
        $query = $this->connection()->table('cpu')->selectRaw('? AS "k"', ['v'])->select('usage_user');

        $this->assertSame([], $query->getRawBindings()['select']);
        $this->assertSame('SELECT "usage_user" FROM "cpu"', $query->toSql());
    }

    #[UnitTest]
    public function testACloneIsIndependentOfItsOriginal(): void
    {
        $query = $this->connection()->table('cpu')->where('host', 'web1')->limit(5);

        $clone = $query->clone()->where('region', 'eu')->limit(10);

        $this->assertSame('SELECT * FROM "cpu" WHERE "host" = ? LIMIT 5', $query->toSql());
        $this->assertSame('SELECT * FROM "cpu" WHERE "host" = ? AND "region" = ? LIMIT 10', $clone->toSql());
    }

    #[UnitTest]
    public function testCloneWithoutResetsTheNamedPropertiesOnTheCloneOnly(): void
    {
        $query = $this->connection()->table('cpu')->select('usage_user')->where('host', 'web1')->limit(5);

        $clone = $query->cloneWithout(['columns', 'wheres', 'limit']);

        $this->assertSame([], $clone->columns);
        $this->assertSame([], $clone->wheres);
        $this->assertNull($clone->limit);
        $this->assertSame(['usage_user'], $query->columns);
        $this->assertSame(5, $query->limit);
    }

    #[UnitTest]
    public function testCloneWithoutBindingsEmptiesTheNamedTypesOnTheCloneOnly(): void
    {
        $query = $this->connection()->table('cpu')->selectRaw('?', [1])->where('host', 'web1');

        $clone = $query->cloneWithoutBindings(['select']);

        $this->assertSame(['web1'], $clone->getBindings());
        $this->assertSame([1, 'web1'], $query->getBindings());
    }

    #[UnitTest]
    public function testANestedWhereQueryIsAddedOnlyWhenItHasWheres(): void
    {
        $query = $this->connection()->table('cpu');

        $query->addNestedWhereQuery($query->forNestedWhere());
        $this->assertSame([], $query->wheres);

        $query->addNestedWhereQuery($query->forNestedWhere()->where('host', 'web1'), 'or');
        $this->assertSame('SELECT * FROM "cpu" WHERE ("host" = ?)', $query->toSql());
        $this->assertSame(['web1'], $query->getBindings());
    }

    #[UnitTest]
    public function testANestedWhereStartsOnTheSameMeasurement(): void
    {
        $this->assertSame('cpu', $this->connection()->table('cpu')->forNestedWhere()->from);
        $this->assertNull($this->connection()->query()->forNestedWhere()->from);
    }

    #[UnitTest]
    public function testNewQueryIsAFreshBuilderOnTheSameConnectionAndGrammar(): void
    {
        $query = $this->connection()->table('cpu')->where('host', 'web1');

        $fresh = $query->newQuery();

        $this->assertNotSame($query, $fresh);
        $this->assertSame($query->getConnection(), $fresh->getConnection());
        $this->assertSame($query->getGrammar(), $fresh->getGrammar());
        $this->assertNull($fresh->from);
        $this->assertSame([], $fresh->wheres);
    }

    #[UnitTest]
    public function testTheGrammarDefaultsToTheConnections(): void
    {
        $connection = $this->connection();
        $grammar = new V1Grammar;

        $this->assertSame($connection->getQueryGrammar(), (new Builder($connection))->getGrammar());
        $this->assertSame($grammar, (new Builder($connection, $grammar))->getGrammar());
    }

    #[UnitTest]
    public function testTheLimitAndOffsetCanBeReadBack(): void
    {
        $query = $this->connection()->table('cpu');

        $this->assertNull($query->getLimit());
        $this->assertNull($query->getOffset());

        $query->forPage(2, 10);

        $this->assertSame(10, $query->getLimit());
        $this->assertSame(10, $query->getOffset());
    }

    #[UnitTest]
    public function testRawMakesAnExpression(): void
    {
        $expression = $this->connection()->table('cpu')->raw('now() - 1h');

        $this->assertInstanceOf(Expression::class, $expression);
        $this->assertSame('now() - 1h', $expression->getValue(new V1Grammar));
    }

    #[UnitTest]
    public function testTheBuilderIsConditionableAndTappable(): void
    {
        $query = $this->connection()->table('cpu')
            ->when(true, fn (Builder $query) => $query->where('host', 'web1'))
            ->when(false, fn (Builder $query) => $query->where('host', 'web2'))
            ->unless(false, fn (Builder $query) => $query->where('region', 'eu'))
            ->tap(fn (Builder $query) => $query->limit(1));

        $this->assertSame('SELECT * FROM "cpu" WHERE "host" = \'web1\' AND "region" = \'eu\' LIMIT 1', $query->toRawSql());
    }

    /**
     * The rows as arrays, for comparing whole rows at once.
     *
     * @param Collection<int, stdClass> $rows
     * @return list<array<string, mixed>>
     */
    private function arrays(Collection $rows): array
    {
        return $rows->map(fn (stdClass $row): array => (array) $row)->values()->all();
    }
}
