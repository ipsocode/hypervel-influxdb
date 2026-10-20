<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature\InfluxQL;

use Closure;
use Hypervel\Support\Facades\DB;
use Ipsocode\InfluxDB\InfluxQL\Regex;
use Ipsocode\InfluxDB\InfluxQL\Version;
use Ipsocode\InfluxDB\Tests\Concerns\MocksInfluxQLDriver;
use Ipsocode\InfluxDB\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/**
 * Holds the two InfluxQL stacks to one another.
 *
 * `InfluxDB::table()` goes through `InfluxQL\Builder` and `InfluxQL\Grammars\Grammar`, the `influxql`
 * driver through `InfluxQL\Driver\Builder` and `InfluxQL\Driver\Grammar`. Each case applies the same
 * calls to a builder from either stack, for every Version, and both must send the same statement
 * or refuse it with the same exception. A change made to one stack alone fails here.
 *
 * Intended differences, none of which a case below may rely on:
 * - Keyword case: the table stack writes `SELECT`, the driver `select`, so statements are compared
 *   case-insensitively until the two agree.
 * - Joins exist only on the driver's builder, so its `delete()` also refuses them, and its message says so.
 *   No shared case joins; the test drops "join, " from that message.
 * - `delete()` returns a bool on the table stack and the number of rows on the driver; only the statement is compared.
 *
 * GrammarTest and Driver\GrammarTest cover what each grammar compiles; BuilderTest covers each builder's own state.
 */
class ParityTest extends TestCase
{
    use MocksInfluxQLDriver;

    private const PINGS = [
        'v1' => '1.8.10',
        'v2' => 'v2.7.12',
        'v3' => '3.11.5',
    ];

    /**
     * @param Closure(object): mixed $apply
     */
    #[DataProvider('cases')]
    public function testBothStacksSendTheSameStatementOrRefuseItTheSameWay(Closure $apply, Version $version): void
    {
        $table = $this->send($version, 'table', $apply);
        $driver = $this->send($version, 'driver', $apply);

        // Only the driver's builder has joins, so its delete() names them among what it refuses.
        $driverRefusal = str_replace('takes no join, limit', 'takes no limit', (string) $driver['refusal']);

        $this->assertSame((string) $table['refusal'], $driverRefusal, 'The stacks refuse differently.');
        $this->assertSame(strtolower($table['statement'] ?? ''), strtolower($driver['statement'] ?? ''), 'The stacks send different statements.');
    }

    /**
     * Every case, for every version.
     *
     * @return iterable<string, array{Closure, Version}>
     */
    public static function cases(): iterable
    {
        foreach (self::definitions() as $name => $apply) {
            foreach (Version::cases() as $version) {
                yield "{$name} on {$version->value}" => [$apply, $version];
            }
        }
    }

    /**
     * The shared builder methods, each in at least one case.
     *
     * @return array<string, Closure(object): mixed>
     */
    private static function definitions(): array
    {
        return [
            'select' => fn (object $q) => $q->select('usage_user', 'host'),
            'addSelect' => fn (object $q) => $q->select('usage_user')->addSelect('host'),
            'where' => fn (object $q) => $q->where('host', 'web1')->where('usage_user', '>', 5),
            'orWhere' => fn (object $q) => $q->where('host', 'web1')->orWhere('host', 'web2'),
            'where regex' => fn (object $q) => $q->where('host', '=~', '^web')->where('region', '!~', '^eu'),
            'where Regex object' => fn (object $q) => $q->where('host', new Regex('^web')),
            'whereIn' => fn (object $q) => $q->whereIn('host', ['web1', 'web2']),
            'whereNotIn' => fn (object $q) => $q->whereNotIn('host', ['web1', 'web2']),
            'whereBetween' => fn (object $q) => $q->whereBetween('usage_user', [1, 5]),
            'whereNotBetween' => fn (object $q) => $q->whereNotBetween('usage_user', [1, 5]),
            'whereColumn' => fn (object $q) => $q->whereColumn('usage_user', '>', 'usage_system'),
            'whereRaw' => fn (object $q) => $q->whereRaw('"host" =~ ?', new Regex('^web')),
            'nested where' => fn (object $q) => $q->where(fn ($w) => $w->where('host', 'web1')->orWhere('host', 'web2'))->where('usage_user', '>', 1),
            'orderBy' => fn (object $q) => $q->orderBy('time', 'desc'),
            'latest' => fn (object $q) => $q->latest(),
            'oldest' => fn (object $q) => $q->oldest(),
            'limit and offset' => fn (object $q) => $q->limit(10)->offset(5),
            'slimit and soffset' => fn (object $q) => $q->slimit(2)->soffset(1),
            'tz' => fn (object $q) => $q->tz('Europe/Amsterdam'),
            'aggregate with groupByTime and fill' => fn (object $q) => $q->selectRaw('mean("usage_user")')->where('host', 'web1')->groupByTime('1h')->fill('previous'),
            'groupByTime with an offset' => fn (object $q) => $q->selectRaw('mean("usage_user")')->groupByTime('1h', '15m')->fill(0),
            'groupBy tag' => fn (object $q) => $q->selectRaw('mean("usage_user")')->groupBy('host'),
            'into' => fn (object $q) => $q->selectRaw('mean("usage_user")')->into('cpu_hourly')->groupByTime('1h'),
            'fromSub' => fn (object $q) => $q->fromSub(fn ($s) => $s->from('cpu')->selectRaw('mean("usage_user") as "mean"')->groupByTime('10m'))->select('mean'),
            'aggregate' => fn (object $q) => $q->aggregate('max', ['usage_user']),
            'value' => fn (object $q) => $q->where('host', 'web1')->value('usage_user'),
            'exists' => fn (object $q) => $q->where('host', 'web1')->exists(),
            'series' => fn (object $q) => $q->series(),
            'delete' => fn (object $q) => $q->where('host', 'web1')->delete(),
            'delete on a time range' => fn (object $q) => $q->whereBetween('time', ['2024-01-01T00:00:00Z', '2024-02-01T00:00:00Z'])->delete(),
            'delete with a limit' => fn (object $q) => $q->where('host', 'web1')->limit(5)->delete(),
        ];
    }

    /**
     * Apply the calls to a builder of one stack, and report what it sent or why it refused.
     *
     * @param 'driver'|'table' $stack
     * @param Closure(object): mixed $apply
     * @return array{statement: null|string, refusal: null|string}
     */
    private function send(Version $version, string $stack, Closure $apply): array
    {
        $this->history = [];
        $this->pingVersion = self::PINGS[$version->value];

        $responses = [self::emptyResponse(), self::emptyResponse(), self::noResultsResponse()];

        if ($stack === 'table') {
            $builder = $this->connection($responses, ['version' => $version->value, 'bucket' => 'telegraf/autogen'])->table('cpu');
        } else {
            $this->responses->append(...$responses);
            $builder = DB::connection("influxql-{$version->value}")->table('cpu');
        }

        try {
            $result = $apply($builder);
            $statements = $this->statements();

            // A case that only builds sends nothing, so its statement is what the builder would send.
            $statement = $statements === [] ? $result->toRawSql() : end($statements);
        } catch (Throwable $e) {
            return ['statement' => null, 'refusal' => $e::class . ': ' . $e->getMessage()];
        }

        return ['statement' => $statement, 'refusal' => null];
    }
}
