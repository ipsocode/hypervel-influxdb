<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature\Eloquent;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Hypervel\Database\Query\Expression;
use Hypervel\Database\QueryException;
use Hypervel\Support\Collection;
use InvalidArgumentException;
use Ipsocode\InfluxDB\Eloquent\Builder;
use Ipsocode\InfluxDB\InfluxQL\Series;
use Ipsocode\InfluxDB\Tests\Concerns\MocksInfluxQLDriver;
use Ipsocode\InfluxDB\Tests\Fixtures\InfluxQL\Cpu;
use Ipsocode\InfluxDB\Tests\Fixtures\InfluxQL\Mem;
use Ipsocode\InfluxDB\Tests\TestCase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

/**
 * Measurement on the `influxql` database driver, which runs InfluxQL on any InfluxDB version, over
 * MocksInfluxQLDriver's mocked 1.x connection; MeasurementOnSqlTest covers the `influxdb` driver.
 */
class MeasurementTest extends TestCase
{
    use MocksInfluxQLDriver;

    public function testAMeasurementReadsItsPointsWithTheTagsOfTheirSeries(): void
    {
        $this->responses->append(self::seriesResponse([
            self::series('cpu', ['time', 'usage_user'], [['2024-01-01T00:00:00Z', 0.64]], ['host' => 'web1']),
            self::series('cpu', ['time', 'usage_user'], [['2024-01-01T00:00:10Z', 0.32]], ['host' => 'web2']),
        ]));

        $cpus = Cpu::query()
            ->select('usage_user')
            ->where('host', '=~', '^web')
            ->whereIn('region', ['eu', 'us'])
            ->where('time', '>=', new DateTimeImmutable('2024-01-01 02:00:00', new DateTimeZone('+02:00')))
            ->groupBy('host')
            ->get();

        $this->assertCount(2, $cpus);
        $this->assertContainsOnlyInstancesOf(Cpu::class, $cpus);
        $this->assertSame('web2', $cpus[1]->host);
        $this->assertSame(0.32, $cpus[1]->usage_user);
        $this->assertEquals(new DateTimeImmutable('2024-01-01T00:00:10Z'), $cpus[1]->time);
        $this->assertSame([
            'select "usage_user" from "cpu" where "host" =~ /^web/ and ("region" = \'eu\' or "region" = \'us\') and "time" >= \'2024-01-01T00:00:00.000000Z\' group by "host"',
        ], $this->statements());
    }

    public function testFindComparesTheTimeAndTheModelKeepsTheShapeOfItsPoint(): void
    {
        $this->responses->append(self::seriesResponse([self::series('cpu', ['time', 'host', 'usage_user'], [['2024-01-01T00:00:10Z', 'web1', 0.64]])]));

        $cpu = Cpu::find('2024-01-01T00:00:10Z');

        $this->assertInstanceOf(Cpu::class, $cpu);
        $this->assertTrue($cpu->exists);
        $this->assertSame(['time' => '2024-01-01T00:00:10.000000Z', 'host' => 'web1', 'usage_user' => 0.64], $cpu->toArray());
        $this->assertSame(['select * from "cpu" where "time" = \'2024-01-01T00:00:10Z\' limit 1'], $this->statements());
    }

    public function testLatestAndOldestOrderByTime(): void
    {
        $this->assertSame('select * from "cpu" order by "time" desc', Cpu::query()->latest()->toSql());
        $this->assertSame('select * from "cpu" order by "time" asc', Cpu::query()->oldest()->toSql());
    }

    public function testALazyRelationIsReadWithoutTheNullCheckInfluxqlCannotSay(): void
    {
        $this->responses->append(
            self::seriesResponse([self::series('cpu', ['time', 'host', 'usage_user'], [['2024-01-01T00:00:00Z', 'web1', 0.64]])]),
            self::seriesResponse([self::series('mem', ['time', 'host', 'used'], [['2024-01-01T00:00:00Z', 'web1', 1024]])]),
        );

        $mem = Cpu::query()->where('host', 'web1')->first()?->mem;

        $this->assertCount(1, $mem);
        $this->assertInstanceOf(Mem::class, $mem->first());
        $this->assertSame(1024, $mem->first()->used);
        $this->assertSame([
            'select * from "cpu" where "host" = \'web1\' limit 1',
            'select * from "mem" where "host" = \'web1\'',
        ], $this->statements());
    }

    public function testARelationIsEagerLoadedThroughOneQueryForAllTheModels(): void
    {
        $this->responses->append(
            self::seriesResponse([self::series('cpu', ['time', 'host', 'usage_user'], [
                ['2024-01-01T00:00:00Z', 'web1', 0.64],
                ['2024-01-01T00:00:00Z', 'web2', 0.32],
            ])]),
            self::seriesResponse([self::series('mem', ['time', 'host', 'used'], [['2024-01-01T00:00:00Z', 'web2', 2048]])]),
        );

        $cpus = Cpu::query()->with('mem')->get();

        $this->assertCount(0, $cpus[0]->mem);
        $this->assertSame(2048, $cpus[1]->mem->first()?->used);
        $this->assertSame([
            'select * from "cpu"',
            'select * from "mem" where ("host" = \'web1\' or "host" = \'web2\')',
        ], $this->statements());
    }

    public function testValueReadsTheFieldItAskedForRatherThanTheTime(): void
    {
        $this->responses->append(
            self::seriesResponse([self::series('cpu', ['time', 'mean'], [['1970-01-01T00:00:00Z', 0.5]])]),
            self::seriesResponse([self::series('cpu', ['time', 'usage_user'], [['2024-01-01T00:00:00Z', 0.64]])]),
        );

        $this->assertSame(0.5, Cpu::query()->value(new Expression('mean("usage_user")')));
        $this->assertSame(0.64, Cpu::query()->latest()->value('cpu.usage_user'));
        $this->assertSame([
            'select mean("usage_user") from "cpu" limit 1',
            'select "usage_user" from "cpu" order by "time" desc limit 1',
        ], $this->statements());
    }

    public function testTheAggregatesAndPluckReadAsTheQueryBuilderDoes(): void
    {
        $this->responses->append(
            self::seriesResponse([self::series('cpu', ['time', 'aggregate'], [['1970-01-01T00:00:00Z', 0.5]])]),
            self::seriesResponse([self::series('cpu', ['time', 'count_usage_system', 'count_usage_user'], [['1970-01-01T00:00:00Z', 4, 3]])]),
            self::seriesResponse([self::series('cpu', ['time', 'mean'], [['2024-01-01T00:00:00Z', 0.5], ['2024-01-01T01:00:00Z', 0.25]])]),
        );

        $this->assertSame(0.5, Cpu::query()->where('host', 'web1')->avg('usage_user'));
        $this->assertSame(4, Cpu::query()->count());
        $this->assertSame(
            ['2024-01-01T00:00:00Z' => 0.5, '2024-01-01T01:00:00Z' => 0.25],
            Cpu::query()->selectRaw('mean("usage_user") as "mean"')->groupByTime('1h')->pluck('mean', 'time')->all(),
        );
        $this->assertSame([
            'select mean("usage_user") as "aggregate" from "cpu" where "host" = \'web1\'',
            'select count(*) from "cpu"',
            'select mean("usage_user") as "mean" from "cpu" group by time(1h)',
        ], $this->statements());
    }

    public function testPaginateCountsThePointsThenReadsThePage(): void
    {
        $this->responses->append(
            self::seriesResponse([self::series('cpu', ['time', 'count_usage_user'], [['1970-01-01T00:00:00Z', 5]])]),
            self::seriesResponse([self::series('cpu', ['time', 'host', 'usage_user'], [
                ['2024-01-01T00:00:20Z', 'web1', 0.3],
                ['2024-01-01T00:00:10Z', 'web1', 0.2],
            ])]),
        );

        $page = Cpu::query()->where('host', 'web1')->latest()->paginate(2, page: 2);

        $this->assertSame(5, $page->total());
        $this->assertSame([0.3, 0.2], $page->getCollection()->pluck('usage_user')->all());
        $this->assertContainsOnlyInstancesOf(Cpu::class, $page->items());
        $this->assertSame([
            'select count(*) from "cpu" where "host" = \'web1\'',
            'select * from "cpu" where "host" = \'web1\' order by "time" desc limit 2 offset 2',
        ], $this->statements());
    }

    public function testCursorPaginationEmbedsTheCastTimeOfThePointItEndedOn(): void
    {
        $this->responses->append(
            self::seriesResponse([self::series('cpu', ['time', 'usage_user'], [
                ['2024-01-01T00:00:00Z', 0.1],
                ['2024-01-01T00:00:10Z', 0.2],
                ['2024-01-01T00:00:20Z', 0.3],
            ])]),
            self::seriesResponse([self::series('cpu', ['time', 'usage_user'], [['2024-01-01T00:00:20Z', 0.3]])]),
        );

        $first = Cpu::query()->cursorPaginate(2);
        $second = Cpu::query()->cursorPaginate(2, cursor: $first->nextCursor());

        $this->assertSame([0.1, 0.2], $first->getCollection()->pluck('usage_user')->all());
        $this->assertSame([0.3], $second->getCollection()->pluck('usage_user')->all());
        $this->assertSame([
            'select * from "cpu" order by "time" asc limit 3',
            'select * from "cpu" where ("time" > \'2024-01-01 00:00:10\') order by "time" asc limit 3',
        ], $this->statements());
    }

    public function testChunkPagesThroughThePointsInTimeOrder(): void
    {
        $this->responses->append(
            self::seriesResponse([self::series('cpu', ['time', 'usage_user'], [['2024-01-01T00:00:00Z', 0.1], ['2024-01-01T00:00:10Z', 0.2]])]),
            self::seriesResponse([self::series('cpu', ['time', 'usage_user'], [['2024-01-01T00:00:20Z', 0.3]])]),
        );

        $chunks = [];

        Cpu::query()->chunk(2, static function (Collection $cpus, int $page) use (&$chunks): void {
            $chunks[$page] = $cpus->pluck('usage_user')->all();
        });

        $this->assertSame([1 => [0.1, 0.2], 2 => [0.3]], $chunks);
        $this->assertSame([
            'select * from "cpu" order by "time" asc limit 2 offset 0',
            'select * from "cpu" order by "time" asc limit 2 offset 2',
        ], $this->statements());
    }

    public function testSeriesComeBackThroughTheModelWithItsScopes(): void
    {
        $this->responses->append(self::seriesResponse([
            self::series('cpu', ['time', 'max'], [['2024-01-01T00:00:10Z', 0.9]], ['host' => 'web1']),
            self::series('cpu', ['time', 'max'], [['2024-01-01T00:00:20Z', 0.8]], ['host' => 'web2']),
        ]));

        $series = Cpu::query()
            ->withGlobalScope('eu', static fn (Builder $query): Builder => $query->where('region', 'eu'))
            ->selectRaw('max("usage_user")')
            ->groupBy('host')
            ->series();

        $this->assertCount(2, $series);
        $this->assertContainsOnlyInstancesOf(Series::class, $series);
        $this->assertSame(['host' => 'web2'], $series[1]->tags);
        $this->assertSame([['2024-01-01T00:00:20Z', 0.8]], $series[1]->values);
        $this->assertSame(['select max("usage_user") from "cpu" where ("region" = \'eu\') group by "host"'], $this->statements());
    }

    public function testTheBuilderDeletesThePointsItsWhereClauseSelectsOnInfluxdb1(): void
    {
        $this->responses->append(self::emptyResponse());

        $this->assertSame(0, Cpu::query()->where('host', 'web1')->delete());
        $this->assertSame(['delete from "cpu" where "host" = \'web1\''], $this->statements());
    }

    /**
     * @param Closure(): mixed $refused
     * @param class-string<Throwable> $exception
     */
    #[DataProvider('refusals')]
    public function testWhatInfluxqlCannotDoIsRefusedBeforeAnythingIsSent(Closure $refused, string $exception, string $message): void
    {
        try {
            $refused();

            $this->fail('No exception was thrown.');
        } catch (Throwable $thrown) {
            $this->assertInstanceOf($exception, $thrown);
            $this->assertSame($message, $thrown->getMessage());
            $this->assertSame([], $this->history);
        }
    }

    /**
     * @return array<string, array{Closure(): mixed, class-string<Throwable>, string}>
     */
    public static function refusals(): array
    {
        $readOnly = 'InfluxDB models are read-only; write points through InfluxDB::writeApi().';
        $point = static fn (): Cpu => (new Cpu)->newFromBuilder(['time' => '2024-01-01T00:00:10Z', 'host' => 'web1', 'usage_user' => 0.64]);

        return [
            'save' => [static fn (): mixed => (new Cpu(['host' => 'web1']))->save(), LogicException::class, $readOnly],
            'create' => [static fn (): mixed => Cpu::query()->create(['host' => 'web1']), LogicException::class, $readOnly],
            'delete' => [static fn (): mixed => $point()->delete(), LogicException::class, $readOnly],
            'an update through the builder' => [
                static fn (): mixed => Cpu::query()->where('host', 'web1')->update(['usage_user' => 1]),
                LogicException::class,
                'InfluxQL has no INSERT, UPDATE, UPSERT or TRUNCATE; write points through InfluxDB::writeApi() instead.',
            ],
            'whereHas' => [
                static fn (): mixed => Cpu::query()->whereHas('mem')->get(),
                RuntimeException::class,
                'InfluxQL has no EXISTS; query the other measurement on its own instead.',
            ],
            'withCount' => [
                static fn (): mixed => Cpu::query()->withCount('mem'),
                InvalidArgumentException::class,
                'InfluxQL has no sub-select among the columns; query the other measurement on its own.',
            ],
            'an order by another column' => [
                static fn (): mixed => Cpu::query()->orderBy('host'),
                InvalidArgumentException::class,
                'InfluxQL sorts by time only; orderBy() takes "time" or a raw expression.',
            ],
            'an SQL operator' => [
                static fn (): mixed => Cpu::query()->where('host', 'like', 'web%'),
                InvalidArgumentException::class,
                '[like] is not an InfluxQL operator; use one of =, <, >, <=, >=, <>, !=, =~, !~.',
            ],
            'chunkById' => [
                static fn (): mixed => Cpu::query()->chunkById(10, static fn (): null => null),
                RuntimeException::class,
                'InfluxQL has no IS NOT NULL; a field or tag cannot be compared with null.',
            ],
        ];
    }

    public function testAStatementTheServerRefusesIsAHypervelQueryException(): void
    {
        $this->responses->append(self::errorResponse('database not found: telegraf'));

        try {
            Cpu::query()->where('host', 'web1')->get();

            $this->fail('No exception was thrown.');
        } catch (QueryException $exception) {
            $this->assertSame('database not found: telegraf', $exception->getPrevious()?->getMessage());
            $this->assertSame('influxql', $exception->getConnectionName());
            $this->assertSame('select * from "cpu" where "host" = \'web1\'', $exception->getRawSql());
        }
    }

    public function testAServerOfAnotherVersionIsRefusedBeforeTheStatementIsSent(): void
    {
        $this->pings['v1.localhost'] = 'v2.7.12';

        try {
            Cpu::query()->get();

            $this->fail('No exception was thrown.');
        } catch (QueryException $exception) {
            $this->assertSame('The influxql driver compiles for InfluxDB 1.x on this connection (version v1), but the server reports version [v2.7.12].', $exception->getPrevious()?->getMessage());
            $this->assertSame(['GET /ping'], $this->requestLines());
        }
    }
}
