<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature\Eloquent;

use Closure;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\Attributes\UseEloquentBuilder;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Query\Expression;
use Ipsocode\InfluxDB\Eloquent\Builder;
use Ipsocode\InfluxDB\Eloquent\Measurement;
use Ipsocode\InfluxDB\Tests\Fixtures\Sql\Cpu;
use Ipsocode\InfluxDB\Tests\Fixtures\Sql\CpuBuilder;
use Ipsocode\InfluxDB\Tests\Fixtures\Sql\Mem;
use Ipsocode\InfluxDB\Tests\TestCase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use UnitEnum;

/**
 * Measurement on the `influxdb` database driver, which runs SQL on InfluxDB 3, with the transport
 * mocked as in SqlConnectionTest; MeasurementTest covers the `influxql` driver.
 */
class MeasurementOnSqlTest extends TestCase
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

    public function testAMeasurementIsKeyedByItsTimeAndKeepsNoTimestamps(): void
    {
        $cpu = new Cpu;

        $this->assertSame('time', $cpu->getKeyName());
        $this->assertSame('string', $cpu->getKeyType());
        $this->assertFalse($cpu->getIncrementing());
        $this->assertFalse($cpu->usesTimestamps());
        $this->assertSame('time', $cpu->getCreatedAtColumn());
        $this->assertNull($cpu->getUpdatedAtColumn());
    }

    public function testEveryAttributeCanBeFilled(): void
    {
        $cpu = new Cpu(['time' => '2024-01-01T00:00:00Z', 'host' => 'web1', 'usage_user' => 0.64]);

        $this->assertSame(['time' => '2024-01-01T00:00:00Z', 'host' => 'web1', 'usage_user' => 0.64], $cpu->getAttributes());
    }

    public function testAMeasurementIsQueriedThroughTheMeasurementBuilder(): void
    {
        $this->assertSame(Builder::class, Cpu::query()::class);
    }

    public function testLatestAndOldestOrderByTime(): void
    {
        $this->assertSame('select * from "cpu" order by "time" desc', Cpu::query()->latest()->toSql());
        $this->assertSame('select * from "cpu" order by "time" asc', Cpu::query()->oldest()->toSql());
    }

    public function testFindComparesTheTimeQualifiedByTheTable(): void
    {
        $this->responses->append(self::rows([['time' => '2024-01-01T00:00:10', 'host' => 'web1', 'usage_user' => 0.64]]));

        $cpu = Cpu::find('2024-01-01T00:00:10Z');

        $this->assertInstanceOf(Cpu::class, $cpu);
        $this->assertTrue($cpu->exists);
        $this->assertSame('2024-01-01T00:00:10', $cpu->getKey());
        $this->assertSame(['time' => '2024-01-01T00:00:10', 'host' => 'web1', 'usage_user' => 0.64], $cpu->toArray());
        $this->assertSame(['select * from "cpu" where "cpu"."time" = \'2024-01-01T00:00:10Z\' limit 1'], $this->statements());
    }

    public function testSeveralKeysAreComparedInOneWhereIn(): void
    {
        $query = Cpu::query()->whereKey(['2024-01-01T00:00:00Z', '2024-01-01T00:00:10Z']);

        $this->assertSame('select * from "cpu" where "cpu"."time" in (\'2024-01-01T00:00:00Z\', \'2024-01-01T00:00:10Z\')', $query->toRawSql());
    }

    public function testWhereHasStillCompilesTheQualifiedExists(): void
    {
        $this->assertSame(
            'select * from "cpu" where exists (select * from "mem" where "cpu"."host" = "mem"."host")',
            Cpu::query()->whereHas('mem')->toSql(),
        );
    }

    public function testARelationIsLoadedThroughAQueryOfItsOwn(): void
    {
        $this->responses->append(self::rows([['time' => '2024-01-01T00:00:00', 'host' => 'web1', 'usage_user' => 0.64]]));
        $this->responses->append(self::rows([['time' => '2024-01-01T00:00:00', 'host' => 'web1', 'used' => 1024]]));

        $cpu = Cpu::query()->where('host', 'web1')->first();
        $mem = $cpu?->mem;

        $this->assertCount(1, $mem);
        $this->assertInstanceOf(Mem::class, $mem->first());
        $this->assertSame(1024, $mem->first()->used);
        $this->assertSame([
            'select * from "cpu" where "host" = \'web1\' limit 1',
            'select * from "mem" where "mem"."host" = \'web1\' and "mem"."host" is not null',
        ], $this->statements());
    }

    public function testARelationIsEagerLoadedThroughOneQueryForAllTheModels(): void
    {
        $this->responses->append(self::rows([
            ['time' => '2024-01-01T00:00:00', 'host' => 'web1', 'usage_user' => 0.64],
            ['time' => '2024-01-01T00:00:00', 'host' => 'web2', 'usage_user' => 0.32],
        ]));
        $this->responses->append(self::rows([['time' => '2024-01-01T00:00:00', 'host' => 'web2', 'used' => 2048]]));

        $cpus = Cpu::query()->with('mem')->get();

        $this->assertCount(0, $cpus[0]->mem);
        $this->assertSame(2048, $cpus[1]->mem->first()?->used);
        $this->assertSame([
            'select * from "cpu"',
            'select * from "mem" where "mem"."host" in (\'web1\', \'web2\')',
        ], $this->statements());
    }

    #[DataProvider('writes')]
    public function testEveryWriteThroughTheModelIsRefusedBeforeAnythingIsSent(Closure $write): void
    {
        try {
            $write();

            $this->fail('No exception was thrown.');
        } catch (LogicException $exception) {
            $this->assertSame('InfluxDB models are read-only; write points through InfluxDB::writeApi().', $exception->getMessage());
            $this->assertSame([], $this->history);
        }
    }

    /**
     * @return array<string, array{Closure(): mixed}>
     */
    public static function writes(): array
    {
        return [
            'save' => [static fn (): mixed => (new Cpu(['host' => 'web1']))->save()],
            'saveQuietly' => [static fn (): mixed => (new Cpu(['host' => 'web1']))->saveQuietly()],
            'create' => [static fn (): mixed => Cpu::query()->create(['host' => 'web1'])],
            'forceCreate' => [static fn (): mixed => Cpu::query()->forceCreate(['host' => 'web1'])],
            'push' => [static fn (): mixed => self::point()->push()],
            'pushQuietly' => [static fn (): mixed => self::point()->pushQuietly()],
            'update' => [static fn (): mixed => self::point()->update(['usage_user' => 1])],
            'delete' => [static fn (): mixed => self::point()->delete()],
            'delete, of a model not read from the server' => [static fn (): mixed => (new Cpu)->delete()],
            'deleteQuietly' => [static fn (): mixed => self::point()->deleteQuietly()],
            'forceDelete' => [static fn (): mixed => self::point()->forceDelete()],
        ];
    }

    public function testDestroyReadsThePointsItWouldDeleteAndIsRefused(): void
    {
        $this->responses->append(self::rows([['time' => '2024-01-01T00:00:10', 'host' => 'web1', 'usage_user' => 0.64]]));

        try {
            Cpu::destroy('2024-01-01T00:00:10Z');

            $this->fail('No exception was thrown.');
        } catch (LogicException $exception) {
            $this->assertSame('InfluxDB models are read-only; write points through InfluxDB::writeApi().', $exception->getMessage());
            $this->assertSame(['select * from "cpu" where "time" in (\'2024-01-01T00:00:10Z\')'], $this->statements());
        }
    }

    public function testSeriesNeedTheInfluxqlDriver(): void
    {
        try {
            Cpu::query()->where('host', 'web1')->series();

            $this->fail('No exception was thrown.');
        } catch (LogicException $exception) {
            $this->assertSame('series() needs the influxql driver, not [influxdb].', $exception->getMessage());
            $this->assertSame([], $this->history);
        }
    }

    public function testAnExpressionsValueIsTheOneFieldSqlReturns(): void
    {
        $this->responses->append(self::rows([['max(cpu.usage_user)' => 0.9]]));

        $this->assertSame(0.9, Cpu::query()->value(new Expression('max("usage_user")')));
        $this->assertSame(['select max("usage_user") from "cpu" limit 1'], $this->statements());
    }

    public function testAMeasurementUsesTheBuilderItNames(): void
    {
        $cpu = new #[UseEloquentBuilder(CpuBuilder::class)] class extends Measurement {
            protected UnitEnum|string|null $connection = 'influxdb';

            protected ?string $table = 'cpu';
        };

        $query = $cpu->newQuery();

        $this->assertInstanceOf(CpuBuilder::class, $query);
        $this->assertSame('select * from "cpu" where "host" = ?', $query->whereHost('web1')->toSql());
    }

    public function testABuilderThatDoesNotExtendTheMeasurementBuilderIsRefused(): void
    {
        $cpu = new #[UseEloquentBuilder(EloquentBuilder::class)] class extends Measurement {
            protected UnitEnum|string|null $connection = 'influxdb';

            protected ?string $table = 'cpu';
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(sprintf('Measurement [%s] must use a builder that extends [%s].', $cpu::class, Builder::class));

        $cpu->newQuery();
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
     * A point as the server returns one, without asking the server.
     */
    private static function point(): Cpu
    {
        return (new Cpu)->newFromBuilder(['time' => '2024-01-01T00:00:10', 'host' => 'web1', 'usage_user' => 0.64]);
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
     * The statement of every SQL request sent, oldest first.
     *
     * @return list<string>
     */
    private function statements(): array
    {
        return array_map(
            static fn (array $entry): string => json_decode((string) $entry['request']->getBody(), true, 512, JSON_THROW_ON_ERROR)['q'],
            $this->history,
        );
    }
}
