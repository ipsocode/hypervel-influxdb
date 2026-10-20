<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Integration;

use Carbon\CarbonImmutable;
use Hypervel\Support\Facades\DB;
use InfluxDB2\Model\WritePrecision;
use InfluxDB2\Point;
use Ipsocode\InfluxDB\InfluxDBManager;
use Ipsocode\InfluxDB\InfluxQL\QueryException;
use Ipsocode\InfluxDB\InfluxQL\V3Connection;
use Ipsocode\InfluxDB\InfluxQL\Version;
use Ipsocode\InfluxDB\Tests\Fixtures\Sql\Series;
use RuntimeException;

/**
 * The package against a real InfluxDB 3 Core: `INFLUXDB_V3_URL` names the server.
 *
 * InfluxQL reaches it through the `/query` v1 compatibility API, and SQL through `/api/v3/query_sql`.
 *
 * @see docs/influxql.md
 * @see docs/sql.md
 */
class InfluxDBV3Test extends V3IntegrationTestCase
{
    public function testTheServerNamesAVersionThreeVersionOnPing(): void
    {
        $connection = $this->manager()->influxql('integration');

        $this->assertInstanceOf(V3Connection::class, $connection);
        $this->assertSame(Version::V3, $connection->getVersion());
        $this->assertMatchesRegularExpression('/^3\.\d+\.\d+/', (string) $connection->getServerVersion());
    }

    public function testPointsReadBackTheSameThroughInfluxQLAndSql(): void
    {
        $this->writeCpu();

        $expected = [
            'count' => 3,
            'host' => 'a',
            'load' => 1.5,
            'note' => 'hello world',
            'region' => 'eu',
            'up' => true,
        ];

        $rows = $this->manager()->table('cpu', 'integration')->get();
        $driverRows = DB::connection('influxql')->table('cpu')->get();
        $sqlRows = DB::connection('sql')->table('cpu')->get();

        $this->assertCount(1, $rows);
        $this->assertCount(1, $driverRows);
        $this->assertCount(1, $sqlRows);
        $this->assertSame('2023-11-14T22:13:20Z', $rows[0]->time);
        $this->assertSame($rows[0]->time, $driverRows[0]->time);
        $this->assertEquals($expected, $this->withoutTime($rows[0]));
        $this->assertEquals($expected, $this->withoutTime($driverRows[0]));
        $this->assertEquals($expected, $this->withoutTime($sqlRows[0]));
        $this->assertSame(1_700_000_000, CarbonImmutable::parse($sqlRows[0]->time, 'UTC')->getTimestamp());
        $this->assertIsFloat($sqlRows[0]->load);
        $this->assertIsInt($sqlRows[0]->count);
        $this->assertIsBool($sqlRows[0]->up);
    }

    public function testAMeasurementOnTheSqlConnectionMatchesWhatTheServerReturns(): void
    {
        $this->writeSeries();

        $this->assertSame(5, Series::query()->count());
        $this->assertSame(['a', 'b', 'a'], Series::query()->where('value', '>', 2)->orderBy('time')->pluck('host')->all());
        $this->assertSame([2, 3, 4], Series::query()
            ->whereBetween('time', ['2023-11-14T22:13:21Z', '2023-11-14T22:13:23Z'])
            ->orderBy('time')
            ->pluck('value')
            ->all());
        $this->assertSame([5, 4], Series::query()->orderByDesc('time')->limit(2)->pluck('value')->all());
        $this->assertSame([3, 4], Series::query()->orderBy('time')->offset(2)->limit(2)->pluck('value')->all());
        $this->assertSame(15, (int) Series::query()->sum('value'));
        $this->assertEquals(3.0, Series::query()->avg('value'));
        $this->assertSame(1, (int) Series::query()->min('value'));
        $this->assertSame(5, (int) Series::query()->max('value'));
        $this->assertSame(['a' => 9, 'b' => 6], Series::query()
            ->selectRaw('host, sum(value) as total')
            ->groupBy('host')
            ->orderBy('host')
            ->pluck('total', 'host')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all());

        $first = Series::query()->orderBy('time')->first();

        $this->assertInstanceOf(Series::class, $first);
        $this->assertSame('a', $first->host);
        $this->assertSame(1, $first->value);
    }

    public function testPretendAndToRawSqlProduceSqlTheServerAcceptsAsIs(): void
    {
        $this->writeSeries();

        $connection = DB::connection('sql');
        $since = CarbonImmutable::parse('2023-11-14T23:13:22+01:00');
        $query = fn () => $connection->table('series')
            ->where('host', "o'brien")
            ->orWhere('time', '>=', $since)
            ->orderBy('time');

        $raw = $query()->toRawSql();

        $this->assertStringContainsString("'o''brien'", $raw);
        $this->assertStringContainsString("'2023-11-14T23:13:22.000000+01:00'", $raw);

        $logged = $connection->pretend(fn () => $query()->get());

        $this->assertSame($raw, $logged[0]['query']);
        $this->assertSame([3, 4, 5], array_map(static fn (object $row): int => $row->value, $connection->select($raw)));
        $this->assertSame([3, 4, 5], $query()->pluck('value')->all());
    }

    public function testTheGrammarRefusesSelectIntoAsTheServerDoes(): void
    {
        $this->assertFalse(Version::V3->supportsSelectInto());

        $this->writeSeries();

        $this->assertRefusedByBoth(
            fn () => $this->manager()->table('series', 'integration')->select('value')->into('copy')->get(),
            'does not support SELECT ... INTO',
            'SELECT "value" INTO "copy" FROM "series"',
        );
    }

    public function testTheGrammarRefusesSlimitAndSoffsetAsTheServerDoes(): void
    {
        $this->assertFalse(Version::V3->supportsSeriesLimits());

        $this->writeSeries();

        $this->assertRefusedByBoth(
            fn () => $this->manager()->table('series', 'integration')->select('value')->groupBy('host')->slimit(1)->get(),
            'SLIMIT',
            'SELECT "value" FROM "series" GROUP BY "host" SLIMIT 1',
        );
        $this->assertRefusedByBoth(
            fn () => $this->manager()->table('series', 'integration')->select('value')->groupBy('host')->soffset(1)->get(),
            'SOFFSET',
            'SELECT "value" FROM "series" GROUP BY "host" SOFFSET 1',
        );
    }

    public function testTheGrammarRefusesDeleteAsTheServerDoes(): void
    {
        $this->assertFalse(Version::V3->supportsDelete());

        $this->writeSeries();

        $this->assertRefusedByBoth(
            fn () => $this->manager()->table('series', 'integration')->where('host', 'a')->delete(),
            'does not support DELETE',
            'DELETE FROM "series" WHERE "host" = \'a\'',
        );

        $this->assertSame(5, $this->manager()->table('series', 'integration')->count());
    }

    public function testTheGrammarAllowsWhatTheServerAccepts(): void
    {
        $this->writeSeries();

        $table = fn () => $this->manager()->table('series', 'integration');

        $this->assertSame(5, $table()->count());
        $this->assertSame([1, 2], $table()->select('value')->orderBy('time')->limit(2)->get()->pluck('value')->all());
        $this->assertSame([3, 4], $table()->select('value')->orderBy('time')->limit(2)->offset(2)->get()->pluck('value')->all());
        $this->assertCount(2, $table()->selectRaw('sum("value") as "total"')->groupBy('host')->get());
        $this->assertSame([2, 3], $table()
            ->whereBetween('time', ['2023-11-14T22:13:21Z', '2023-11-14T22:13:22Z'])
            ->orderBy('time')
            ->pluck('value')
            ->all());
    }

    private function manager(): InfluxDBManager
    {
        return $this->app->get(InfluxDBManager::class);
    }

    /**
     * Assert that the grammar refuses a query before sending it, and that the server rejects the statement it would have sent.
     *
     * @param callable(): mixed $builder
     */
    private function assertRefusedByBoth(callable $builder, string $grammarMessage, string $statement): void
    {
        try {
            $builder();

            $this->fail('The grammar compiled a statement InfluxDB 3 refuses.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($grammarMessage, $exception->getMessage());
        }

        try {
            $this->manager()->influxql('integration')->select($statement);

            $this->fail('The server accepted a statement the grammar refuses.');
        } catch (QueryException $exception) {
            $this->assertNotSame('', $exception->getError());
        }
    }

    private function writeCpu(): void
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
    }

    /**
     * Five points a second apart from 22:13:20Z, with the values 1 to 5 on hosts a, b, a, b and a.
     */
    private function writeSeries(): void
    {
        $this->manager()->writeApi('integration')->writeRaw(implode("\n", [
            'series,host=a value=1i 1700000000',
            'series,host=b value=2i 1700000001',
            'series,host=a value=3i 1700000002',
            'series,host=b value=4i 1700000003',
            'series,host=a value=5i 1700000004',
        ]), WritePrecision::S);
    }

    /**
     * The row as an array, ordered by key and without its time.
     *
     * @return array<string, mixed>
     */
    private function withoutTime(object $row): array
    {
        $values = (array) $row;

        unset($values['time']);
        ksort($values);

        return $values;
    }
}
