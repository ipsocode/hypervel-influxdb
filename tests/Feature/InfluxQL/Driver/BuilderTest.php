<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature\InfluxQL\Driver;

use Closure;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use GuzzleHttp\Psr7\Response;
use Hypervel\Database\Connection;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Database\Query\Expression;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Foundation\Testing\Attributes\UnitTest;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\DB;
use InvalidArgumentException;
use Ipsocode\InfluxDB\InfluxQL\Driver\Builder;
use Ipsocode\InfluxDB\InfluxQL\Driver\Grammar;
use Ipsocode\InfluxDB\InfluxQL\Regex;
use Ipsocode\InfluxDB\InfluxQL\Series;
use Ipsocode\InfluxDB\InfluxQL\Version;
use Ipsocode\InfluxDB\Tests\Concerns\MocksInfluxQLDriver;
use Ipsocode\InfluxDB\Tests\TestCase;
use LogicException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * How the `influxql` driver's builder builds a query, and how it reads what comes back.
 *
 * GrammarTest covers what the grammar compiles and refuses; this covers what the builder adds on top.
 */
class BuilderTest extends TestCase
{
    use MocksInfluxQLDriver;

    #[UnitTest]
    public function testTheClausesOnlyInfluxqlHasAreSetThroughMethodsOfTheirOwn(): void
    {
        $query = $this->table('telegraf.autogen.cpu')
            ->selectRaw('mean("usage_user") as "mean"')
            ->into('telegraf.autogen.cpu_hourly')
            ->where('host', 'web1')
            ->groupByTime('1h')
            ->fill('previous')
            ->orderByDesc('time')
            ->limit(10)
            ->slimit(2)
            ->soffset(1)
            ->tz('Europe/Amsterdam');

        $this->assertSame(
            'select mean("usage_user") as "mean" into "telegraf"."autogen"."cpu_hourly" from "telegraf"."autogen"."cpu"'
            . ' where "host" = \'web1\' group by time(1h) fill(previous) order by "time" desc limit 10 slimit 2 soffset 1 tz(\'Europe/Amsterdam\')',
            $query->toRawSql(),
        );
    }

    #[UnitTest]
    public function testIntoTakesAnExpressionWithAMeasurementBackreference(): void
    {
        $query = $this->builder()->selectRaw('mean(*)')->fromRaw('?', [new Regex('.*')])->groupByTime('1h')
            ->into(new Expression('"downsampled"."autogen".:MEASUREMENT'));

        $this->assertSame('select mean(*) into "downsampled"."autogen".:MEASUREMENT from /.*/ group by time(1h)', $query->toRawSql());
    }

    #[UnitTest]
    public function testGroupByTimeTakesAnIntervalAndAnOffset(): void
    {
        $this->assertSame('select * from "cpu" group by time(10m)', $this->table()->groupByTime('10m')->toSql());
        $this->assertSame('select * from "cpu" group by time(1h, -15m), "host"', $this->table()->groupByTime('1h', '-15m')->groupBy('host')->toSql());
    }

    #[UnitTest]
    #[DataProvider('durationsThatAreNot')]
    public function testGroupByTimeRefusesWhatIsNotADurationLiteral(string $interval, ?string $offset, string $named): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs("[{$named}] is not an InfluxQL duration literal, such as 10m or 1h.");

        $this->table()->groupByTime($interval, $offset);
    }

    /**
     * @return array<string, array{string, ?string, string}>
     */
    public static function durationsThatAreNot(): array
    {
        return [
            'an interval in words' => ['ten minutes', null, 'ten minutes'],
            'an interval with a closing parenthesis' => ['1h), "host', null, '1h), "host'],
            'an offset in words' => ['1h', 'a quarter', 'a quarter'],
        ];
    }

    #[UnitTest]
    #[DataProvider('fills')]
    public function testFillTakesANumberOrAKeyword(float|int|string $value, string $expected): void
    {
        $this->assertSame(
            "select mean(\"value\") from \"cpu\" group by time(1h) fill({$expected})",
            $this->table()->selectRaw('mean("value")')->groupByTime('1h')->fill($value)->toSql(),
        );
    }

    /**
     * @return array<string, array{float|int|string, string}>
     */
    public static function fills(): array
    {
        return [
            'none' => ['none', 'none'],
            'null' => ['null', 'null'],
            'previous, in capitals' => ['PREVIOUS', 'previous'],
            'linear' => ['linear', 'linear'],
            'zero' => [0, '0'],
            'a number in a string' => ['-1.5', '-1.5'],
            'a float' => [0.5, '0.5'],
        ];
    }

    #[UnitTest]
    public function testFillRefusesAWordThatIsNotAKeyword(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('fill() takes a number or one of none, null, previous, linear, not [zero].');

        $this->table()->fill('zero');
    }

    #[UnitTest]
    public function testSlimitKeepsANonNegativeValueAndSoffsetCountsFromZero(): void
    {
        $this->assertSame('select * from "cpu" group by * slimit 2', $this->table()->groupBy('*')->slimit(2)->slimit(-1)->toSql());
        $this->assertSame('select * from "cpu" group by *', $this->table()->groupBy('*')->slimit(2)->slimit(null)->toSql());
        $this->assertSame('select * from "cpu" group by * soffset 0', $this->table()->groupBy('*')->soffset(-3)->toSql());
    }

    #[UnitTest]
    public function testTzRefusesANameThatIsNotATimeZone(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('[Mars/Olympus_Mons] is not a time zone name.');

        $this->table()->tz('Mars/Olympus_Mons');
    }

    #[UnitTest]
    #[DataProvider('clausesAVersionDoesNotRun')]
    public function testAVersionRefusesTheClausesItDoesNotRun(Version $version, Closure $clause, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs($message);

        $clause($this->table(version: $version))->toSql();
    }

    /**
     * @return array<string, array{Version, Closure(Builder): Builder, string}>
     */
    public static function clausesAVersionDoesNotRun(): array
    {
        return [
            'into on 2.x' => [Version::V2, static fn (Builder $query): Builder => $query->into('cpu_hourly'), 'InfluxDB 2.x does not support SELECT ... INTO; downsample with a task instead.'],
            'into on 3' => [Version::V3, static fn (Builder $query): Builder => $query->into('cpu_hourly'), 'InfluxDB 3 does not support SELECT ... INTO; downsample with the processing engine instead.'],
            'slimit on 3' => [Version::V3, static fn (Builder $query): Builder => $query->slimit(2), 'InfluxDB 3 does not support SLIMIT.'],
            'soffset on 3' => [Version::V3, static fn (Builder $query): Builder => $query->soffset(1), 'InfluxDB 3 does not support SOFFSET.'],
        ];
    }

    #[UnitTest]
    public function testSeriesLimitsOfZeroCompileToNothingOnInfluxdb3(): void
    {
        $this->assertSame('select * from "cpu" group by *', $this->table(version: Version::V3)->groupBy('*')->slimit(0)->soffset(0)->toSql());
    }

    #[UnitTest]
    public function testWhereTakesTheValueAsItsSecondArgument(): void
    {
        $query = $this->table()->where('host', 'web1')->orWhere('host', 'web2');

        $this->assertSame('select * from "cpu" where "host" = ? or "host" = ?', $query->toSql());
        $this->assertSame(['web1', 'web2'], $query->getBindings());
    }

    #[UnitTest]
    public function testAnArrayOfWheresIsGroupedAndEachOfThemGoesThroughWhere(): void
    {
        $this->assertSame(
            'select * from "cpu" where ("host" = \'web1\' and "region" =~ /^eu/)',
            $this->table()->where(['cpu.host' => 'web1', ['region', '=~', '^eu']])->toRawSql(),
        );
    }

    #[UnitTest]
    #[DataProvider('operatorsInfluxqlDoesNotHave')]
    public function testWhereRefusesAnOperatorInfluxqlDoesNotHave(Closure $where, string $named): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs("[{$named}] is not an InfluxQL operator; use one of =, <, >, <=, >=, <>, !=, =~, !~.");

        $where($this->table());
    }

    /**
     * @return array<string, array{Closure(Builder): Builder, string}>
     */
    public static function operatorsInfluxqlDoesNotHave(): array
    {
        return [
            'like' => [static fn (Builder $query): Builder => $query->where('host', 'like', 'web%'), 'like'],
            'LIKE' => [static fn (Builder $query): Builder => $query->where('host', 'LIKE', 'web%'), 'LIKE'],
            'ilike, or-ed' => [static fn (Builder $query): Builder => $query->orWhere('host', 'ilike', 'web%'), 'ilike'],
            'the null-safe operator' => [static fn (Builder $query): Builder => $query->where('host', '<=>', 'web1'), '<=>'],
            'a bitwise operator' => [static fn (Builder $query): Builder => $query->where('flags', '&', 4), '&'],
            'a non-string operator' => [static fn (Builder $query): Builder => $query->where('value', 1, 2), 'int'],
            'inside an array of wheres' => [static fn (Builder $query): Builder => $query->where([['host', 'like', 'web%']]), 'like'],
            'between two columns' => [static fn (Builder $query): Builder => $query->whereColumn('host', 'like', 'region'), 'like'],
        ];
    }

    #[UnitTest]
    public function testAnOperatorWithNoValueIsTheValue(): void
    {
        $this->assertSame(
            'select * from "cpu" where "host" = \'web1\' or "host" = \'web2\'',
            $this->table()->where('host', 'web1', null)->orWhere('host', 'web2', null)->toRawSql(),
        );
    }

    #[UnitTest]
    public function testARegularExpressionIsMatchedWithItsOwnOperators(): void
    {
        $this->assertSame('select * from "cpu" where "host" =~ /^web/', $this->table()->where('host', '=~', '^web')->toRawSql());
        $this->assertSame('select * from "cpu" where "host" !~ /^web/', $this->table()->where('host', '!~', '^web')->toRawSql());
        $this->assertSame('select * from "cpu" where "host" =~ /^web/', $this->table()->where('host', new Regex('^web'))->toRawSql());
        $this->assertSame('select * from "cpu" where "host" !~ /^web/', $this->table()->where('host', '!=', new Regex('^web'))->toRawSql());
        $this->assertSame('select * from "cpu" where "host" !~ /^web/', $this->table()->where('host', '<>', new Regex('^web'))->toRawSql());
    }

    #[UnitTest]
    public function testARegularExpressionWithAnotherOperatorIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('A regular expression is matched with =~ or !~, not >.');

        $this->table()->where('host', '>', new Regex('^web'));
    }

    #[UnitTest]
    public function testTheRegexOperatorsTakeNothingButARegularExpression(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('The =~ operator takes a regular expression, as a string or a Regex.');

        $this->table()->where('value', '=~', 1);
    }

    #[UnitTest]
    #[DataProvider('comparisonsLeftForTheGrammar')]
    public function testANullOrASubSelectIsLeftForTheGrammarToRefuse(Closure $where, string $message): void
    {
        $query = $where($this->table());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs($message);

        $query->toSql();
    }

    /**
     * @return array<string, array{Closure(Builder): Builder, string}>
     */
    public static function comparisonsLeftForTheGrammar(): array
    {
        return [
            'a null value' => [static fn (Builder $query): Builder => $query->where('host', null), 'InfluxQL has no IS NULL; a field or tag cannot be compared with null.'],
            'a null value, negated' => [static fn (Builder $query): Builder => $query->where('host', '!=', null), 'InfluxQL has no IS NOT NULL; a field or tag cannot be compared with null.'],
            'a sub-select value' => [static fn (Builder $query): Builder => $query->where('value', '>', static fn (Builder $query) => $query->selectRaw('max("value")')->from('mem')), 'InfluxQL cannot compare a column with a sub-select.'],
        ];
    }

    #[UnitTest]
    public function testAColumnQualifiedByTheMeasurementIsTakenUnqualified(): void
    {
        $query = $this->table()
            ->select('cpu.time', 'cpu.host as h', 'cpu.value::field')
            ->addSelect('cpu.usage_user')
            ->where('cpu.host', 'web1')
            ->whereColumn('cpu.usage_user', '>', 'cpu.usage_system')
            ->whereIn('cpu.region', ['eu', 'us'])
            ->whereBetween('cpu.value', [1, 10])
            ->orderBy('cpu.time', 'desc');

        $this->assertSame(
            'select "time", "host" as "h", "value"::field, "usage_user" from "cpu" where "host" = ? and "usage_user" > "usage_system"'
            . ' and ("region" = ? or "region" = ?) and ("value" >= ? and "value" <= ?) order by "time" desc',
            $query->toSql(),
        );
        $this->assertSame('select * from "cpu"', $this->table()->select('cpu.*')->toSql());
    }

    #[UnitTest]
    public function testAQualifiedMeasurementQualifiesItsColumnsByItsWholeName(): void
    {
        $query = $this->table('telegraf.autogen.cpu')->select('telegraf.autogen.cpu.time', 'cpu.time');

        $this->assertSame('select "time", "cpu.time" from "telegraf"."autogen"."cpu"', $query->toSql());
    }

    #[UnitTest]
    public function testAColumnOfAnotherMeasurementStaysQualified(): void
    {
        $this->assertSame('select * from "cpu" where "mem.host" = ?', $this->table()->where('mem.host', 'web1')->toSql());
        $this->assertSame('select "cpu.time" from (select * from "cpu")', $this->builder()->fromRaw('(select * from "cpu")')->select('cpu.time')->toSql());
    }

    #[UnitTest]
    public function testWhereColumnTakesTheSecondColumnAsItsSecondArgument(): void
    {
        $this->assertSame('select * from "cpu" where "usage_user" = "usage_system"', $this->table()->whereColumn('usage_user', 'usage_system')->toSql());
        $this->assertSame('select * from "cpu" where ("usage_user" > "usage_system")', $this->table()->whereColumn([['cpu.usage_user', '>', 'cpu.usage_system']])->toSql());
    }

    #[UnitTest]
    public function testWhereInRefusesASubSelect(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('InfluxQL cannot compare a column with a sub-select; pass whereIn() the values themselves.');

        $this->table()->whereIn('host', static fn (Builder $query) => $query->select('host')->from('mem'));
    }

    #[UnitTest]
    public function testWhereBetweenComparesWithTheFirstTwoBounds(): void
    {
        $this->assertSame('select * from "cpu" where ("value" >= 1 and "value" <= 5)', $this->table()->whereBetween('value', [1, 5, 9])->toRawSql());
        $this->assertSame(
            'select * from "cpu" where ("time" >= \'2024-01-01T00:00:00.000000Z\' and "time" <= \'2024-01-01T02:00:00.000000Z\')',
            $this->table()->whereBetween('time', new DatePeriod(
                new DateTimeImmutable('2024-01-01T00:00:00Z'),
                new DateInterval('PT1H'),
                new DateTimeImmutable('2024-01-01T02:00:00Z'),
            ))->toRawSql(),
        );
    }

    #[UnitTest]
    public function testWhereBetweenNeedsTwoBounds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('whereBetween needs a lower and an upper bound.');

        $this->table()->whereBetween('value', [1]);
    }

    #[UnitTest]
    public function testWhereBetweenRefusesASubSelect(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('InfluxQL cannot compare a sub-select; pass whereBetween() a column.');

        $this->table()->whereBetween(static fn (Builder $query) => $query->selectRaw('max("value")')->from('mem'), [1, 10]);
    }

    #[UnitTest]
    public function testARawWhereTakesASingleBindingBare(): void
    {
        $this->assertSame('select * from "cpu" where "host" =~ /^web/', $this->table()->whereRaw('"host" =~ ?', new Regex('^web'))->toRawSql());
        $this->assertSame(
            'select * from "cpu" where time > \'2024-01-01T00:00:00.000000Z\' or "host" = \'web1\'',
            $this->table()->whereRaw('time > ?', new DateTimeImmutable('2024-01-01T00:00:00Z'))->orWhereRaw('"host" = ?', 'web1')->toRawSql(),
        );
        $this->assertSame('select * from "cpu" where time > now() - 1h', $this->table()->whereRaw('time > now() - 1h')->toRawSql());
    }

    #[UnitTest]
    public function testWhereNotNullOnAColumnComparedForEqualityIsDropped(): void
    {
        $this->assertSame('select * from "cpu" where "host" = ?', $this->table()->where('host', 'web1')->whereNotNull('host')->toSql());
        $this->assertSame(
            'select * from "cpu" where "host" = ? and "region" = ?',
            $this->table()->where('host', 'web1')->where('region', 'eu')->whereNotNull(['host', 'region'])->toSql(),
        );
    }

    #[UnitTest]
    public function testTheConstraintsOfARelationCompileWithoutTheNullCheck(): void
    {
        // HasOneOrMany::addConstraints(), on the qualified foreign key.
        $query = $this->table('mem')->where('mem.host', '=', 'web1')->whereNotNull('mem.host');

        $this->assertSame('select * from "mem" where "host" = \'web1\'', $query->toRawSql());
    }

    #[UnitTest]
    #[DataProvider('nullChecksLeftForTheGrammar')]
    public function testAnyOtherNullCheckIsLeftForTheGrammarToRefuse(Closure $where, string $message): void
    {
        $query = $where($this->table());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs($message);

        $query->toSql();
    }

    /**
     * @return array<string, array{Closure(Builder): Builder, string}>
     */
    public static function nullChecksLeftForTheGrammar(): array
    {
        $isNotNull = 'InfluxQL has no IS NOT NULL; a field or tag cannot be compared with null.';

        return [
            'on its own' => [static fn (Builder $query): Builder => $query->whereNotNull('host'), $isNotNull],
            'after an inequality' => [static fn (Builder $query): Builder => $query->where('host', '!=', 'web1')->whereNotNull('host'), $isNotNull],
            'after an equality or-ed' => [static fn (Builder $query): Builder => $query->where('region', 'eu')->orWhere('host', 'web1')->whereNotNull('host'), $isNotNull],
            'or-ed itself' => [static fn (Builder $query): Builder => $query->where('host', 'web1')->orWhereNotNull('host'), $isNotNull],
            'on a column not compared' => [static fn (Builder $query): Builder => $query->where('host', 'web1')->whereNotNull(['host', 'region']), $isNotNull],
            'whereNull, after an equality' => [static fn (Builder $query): Builder => $query->where('host', 'web1')->whereNull('host'), 'InfluxQL has no IS NULL; a field or tag cannot be compared with null.'],
        ];
    }

    #[UnitTest]
    public function testOrderByTakesTimeQualifiedOrNot(): void
    {
        $this->assertSame('select * from "cpu" order by "time" asc', $this->table()->orderBy('cpu.time')->toSql());
        $this->assertSame('select * from "cpu" order by "TIME" desc', $this->table()->orderByDesc('TIME')->toSql());
        $this->assertSame('select * from "cpu" order by time desc', $this->table()->orderBy(new Expression('time'), 'desc')->toSql());
    }

    #[UnitTest]
    public function testLatestAndOldestOrderByTime(): void
    {
        $this->assertSame('select * from "cpu" order by "time" desc', $this->table()->latest()->toSql());
        $this->assertSame('select * from "cpu" order by "time" asc', $this->table()->oldest()->toSql());
        $this->assertSame('select * from "cpu" order by "time" desc', $this->table()->latest('cpu.time')->toSql());
    }

    #[UnitTest]
    #[DataProvider('ordersInfluxqlDoesNotHave')]
    public function testOrderByRefusesAnythingButTime(Closure $order): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('InfluxQL sorts by time only; orderBy() takes "time" or a raw expression.');

        $order($this->table());
    }

    /**
     * @return array<string, array{Closure(Builder): Builder}>
     */
    public static function ordersInfluxqlDoesNotHave(): array
    {
        return [
            'another column' => [static fn (Builder $query): Builder => $query->orderBy('host')],
            'another column, qualified' => [static fn (Builder $query): Builder => $query->orderByDesc('cpu.host')],
            'the default of Hypervel\'s latest()' => [static fn (Builder $query): Builder => $query->latest('created_at')],
            'a sub-select' => [static fn (Builder $query): Builder => $query->orderBy(static fn (Builder $query) => $query->select('time')->from('mem'))],
        ];
    }

    #[UnitTest]
    public function testAnOrderByASequenceOfValuesIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('InfluxQL sorts by time only; it cannot order by a sequence of values.');

        $this->table()->inOrderOf('host', ['web2', 'web1']);
    }

    #[UnitTest]
    #[DataProvider('subSelectsAmongTheColumns')]
    public function testASubSelectAmongTheColumnsIsRefused(Closure $select): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('InfluxQL has no sub-select among the columns; query the other measurement on its own.');

        $select($this->table());
    }

    /**
     * @return array<string, array{Closure(Builder): Builder}>
     */
    public static function subSelectsAmongTheColumns(): array
    {
        $sub = static fn (Builder $query) => $query->selectRaw('count("used")')->from('mem');

        return [
            'select' => [static fn (Builder $query): Builder => $query->select(['used' => $sub])],
            'addSelect' => [static fn (Builder $query): Builder => $query->addSelect(['used' => $sub])],
            'selectSub' => [static fn (Builder $query): Builder => $query->selectSub($sub, 'used')],
        ];
    }

    #[UnitTest]
    public function testASubqueryIsSelectedFromWithoutAName(): void
    {
        $query = $this->builder()->select('mean')->from(
            static fn (Builder $query) => $query->from('cpu')->selectRaw('mean("value") as "mean"')->where('host', 'web1')->groupByTime('10m'),
        );

        $this->assertSame('select "mean" from (select mean("value") as "mean" from "cpu" where "host" = ? group by time(10m))', $query->toSql());
        $this->assertSame('select "mean" from (select mean("value") as "mean" from "cpu" where "host" = \'web1\' group by time(10m))', $query->toRawSql());
        $this->assertSame('select * from (select * from "cpu")', $this->builder()->fromSub($this->table())->toSql());
        $this->assertSame('select * from (select * from "cpu")', $this->builder()->fromSub('select * from "cpu"')->toSql());
    }

    #[UnitTest]
    public function testASubqueryCannotBeNamed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('InfluxQL cannot name a subquery; select from it without [sub].');

        $this->builder()->from(static fn (Builder $query) => $query->from('cpu'), 'sub');
    }

    #[UnitTest]
    public function testADeleteTakesNoId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('A point has no id to delete it by; constrain the query with where() instead.');

        $this->table()->delete('2024-01-01T00:00:00Z');
    }

    /**
     * A tripwire for Hypervel adding where-methods of its own.
     *
     * The builder inherits them all, and a new one would compile whatever Hypervel's grammar writes for it, SQL
     * and all, so whereMethods() names each with its InfluxQL or its refusal. CI resolves hypervel/components
     * fresh on every run, so a where-method Hypervel adds fails this before it reaches an application.
     */
    #[UnitTest]
    public function testEveryWhereMethodOfHypervelsBuilderIsSwept(): void
    {
        $hypervel = array_values(array_filter(
            array_map(static fn (ReflectionMethod $method): string => $method->getName(), (new ReflectionClass(QueryBuilder::class))->getMethods(ReflectionMethod::IS_PUBLIC)),
            static fn (string $method): bool => preg_match('/^(?:or)?[wW]here[A-Z]/', $method) === 1,
        ));

        $this->assertNotEmpty($hypervel, 'Found no where-methods on Hypervel\'s Query\Builder; the scan is broken.');
        $this->assertEqualsCanonicalizing($hypervel, array_keys(self::whereMethods()), 'Give each where-method of Hypervel\'s Query\Builder a case in whereMethods(): what it compiles to, or its refusal.');
    }

    #[UnitTest]
    #[DataProvider('whereMethods')]
    public function testEachWhereMethodCompilesToInfluxqlOrIsRefusedBeforeAnythingIsSent(Closure $where, string $expected): void
    {
        try {
            $compiled = $where($this->table())->toSql();
        } catch (LogicException|RuntimeException $refusal) {
            $compiled = $refusal->getMessage();
        }

        $this->assertSame($expected, $compiled);
    }

    /**
     * One call of each where-method of Hypervel's builder, with the InfluxQL it compiles to or the message it is refused with.
     *
     * @return array<string, array{Closure(Builder): Builder, string}>
     */
    public static function whereMethods(): array
    {
        $where = static fn (string $where): string => 'select * from "cpu" where ' . $where;
        $or = static fn (string $where): string => 'select * from "cpu" where "region" = ? or ' . $where;
        $part = static fn (string $part): string => "InfluxQL cannot compare the {$part} part of a timestamp; compare time with a date instead.";
        $sub = static fn (Builder $query): Builder => $query->from('mem');

        $not = 'InfluxQL has no NOT; negate the comparison instead, such as != for = or !~ for =~.';
        $vectors = 'Vector distance queries are only supported by Postgres and MariaDB.';
        $binary = 'InfluxQL has no binary comparison.';
        $like = 'InfluxQL has no LIKE; match a regular expression with =~ instead.';
        $nullSafe = 'InfluxQL has no null-safe comparison; a field or tag cannot be compared with null.';
        $in = 'InfluxQL has no IN; use whereIn(), which compiles to one comparison per value.';
        $notIn = 'InfluxQL has no NOT IN; use whereNotIn(), which compiles to one comparison per value.';
        $isNull = 'InfluxQL has no IS NULL; a field or tag cannot be compared with null.';
        $isNotNull = 'InfluxQL has no IS NOT NULL; a field or tag cannot be compared with null.';
        $betweenColumns = 'InfluxQL has no BETWEEN; compare the column with each bound in its own whereColumn() instead.';
        $valueBetween = 'InfluxQL has no BETWEEN; compare each column with the value in its own where() instead.';
        $exists = 'InfluxQL has no EXISTS; query the other measurement on its own instead.';
        $notExists = 'InfluxQL has no NOT EXISTS; query the other measurement on its own instead.';
        $rowValues = 'InfluxQL has no row values; compare each column in its own where() instead.';
        $jsonContains = 'This database engine does not support JSON contains operations.';
        $jsonOverlaps = 'This database engine does not support JSON overlaps operations.';
        $jsonContainsKey = 'This database engine does not support JSON contains key operations.';
        $jsonLength = 'This database engine does not support JSON length operations.';
        $fullText = 'This database engine does not support fulltext search operations.';

        return [
            'whereNot' => [static fn (Builder $query): Builder => $query->whereNot('host', 'web1'), $not],
            'orWhereNot' => [static fn (Builder $query): Builder => $query->orWhereNot('host', 'web1'), $not],
            'whereColumn' => [static fn (Builder $query): Builder => $query->whereColumn('usage_user', '>', 'usage_system'), $where('"usage_user" > "usage_system"')],
            'orWhereColumn' => [static fn (Builder $query): Builder => $query->where('region', 'eu')->orWhereColumn('usage_user', '>', 'usage_system'), $or('"usage_user" > "usage_system"')],
            'whereVectorSimilarTo' => [static fn (Builder $query): Builder => $query->whereVectorSimilarTo('embedding', [0.5]), $vectors],
            'whereVectorDistanceLessThan' => [static fn (Builder $query): Builder => $query->whereVectorDistanceLessThan('embedding', [0.5], 0.3), $vectors],
            'orWhereVectorDistanceLessThan' => [static fn (Builder $query): Builder => $query->orWhereVectorDistanceLessThan('embedding', [0.5], 0.3), $vectors],
            'whereRaw' => [static fn (Builder $query): Builder => $query->whereRaw('time > now() - 1h'), $where('time > now() - 1h')],
            'orWhereRaw' => [static fn (Builder $query): Builder => $query->where('region', 'eu')->orWhereRaw('time > now() - 1h'), $or('time > now() - 1h')],
            'whereBinary' => [static fn (Builder $query): Builder => $query->whereBinary('host', 'web1'), $binary],
            'orWhereBinary' => [static fn (Builder $query): Builder => $query->orWhereBinary('host', 'web1'), $binary],
            'whereNotBinary' => [static fn (Builder $query): Builder => $query->whereNotBinary('host', 'web1'), $binary],
            'orWhereNotBinary' => [static fn (Builder $query): Builder => $query->orWhereNotBinary('host', 'web1'), $binary],
            'whereLike' => [static fn (Builder $query): Builder => $query->whereLike('host', 'web%'), $like],
            'orWhereLike' => [static fn (Builder $query): Builder => $query->orWhereLike('host', 'web%'), $like],
            'whereNotLike' => [static fn (Builder $query): Builder => $query->whereNotLike('host', 'web%'), $like],
            'orWhereNotLike' => [static fn (Builder $query): Builder => $query->orWhereNotLike('host', 'web%'), $like],
            'whereNullSafeEquals' => [static fn (Builder $query): Builder => $query->whereNullSafeEquals('host', 'web1'), $nullSafe],
            'orWhereNullSafeEquals' => [static fn (Builder $query): Builder => $query->orWhereNullSafeEquals('host', 'web1'), $nullSafe],
            'whereIn' => [static fn (Builder $query): Builder => $query->whereIn('host', ['web1', 'web2']), $where('("host" = ? or "host" = ?)')],
            'orWhereIn' => [static fn (Builder $query): Builder => $query->where('region', 'eu')->orWhereIn('host', ['web1', 'web2']), $or('("host" = ? or "host" = ?)')],
            'whereNotIn' => [static fn (Builder $query): Builder => $query->whereNotIn('host', ['web1', 'web2']), $where('("host" != ? and "host" != ?)')],
            'orWhereNotIn' => [static fn (Builder $query): Builder => $query->where('region', 'eu')->orWhereNotIn('host', ['web1', 'web2']), $or('("host" != ? and "host" != ?)')],
            'whereIntegerInRaw' => [static fn (Builder $query): Builder => $query->whereIntegerInRaw('value', [1, 2]), $in],
            'orWhereIntegerInRaw' => [static fn (Builder $query): Builder => $query->orWhereIntegerInRaw('value', [1, 2]), $in],
            'whereIntegerNotInRaw' => [static fn (Builder $query): Builder => $query->whereIntegerNotInRaw('value', [1, 2]), $notIn],
            'orWhereIntegerNotInRaw' => [static fn (Builder $query): Builder => $query->orWhereIntegerNotInRaw('value', [1, 2]), $notIn],
            'whereNull' => [static fn (Builder $query): Builder => $query->whereNull('host'), $isNull],
            'orWhereNull' => [static fn (Builder $query): Builder => $query->orWhereNull('host'), $isNull],
            'whereNotNull' => [static fn (Builder $query): Builder => $query->whereNotNull('host'), $isNotNull],
            'whereBetween' => [static fn (Builder $query): Builder => $query->whereBetween('value', [1, 5]), $where('("value" >= ? and "value" <= ?)')],
            'whereBetweenColumns' => [static fn (Builder $query): Builder => $query->whereBetweenColumns('value', ['low', 'high']), $betweenColumns],
            'orWhereBetween' => [static fn (Builder $query): Builder => $query->where('region', 'eu')->orWhereBetween('value', [1, 5]), $or('("value" >= ? and "value" <= ?)')],
            'orWhereBetweenColumns' => [static fn (Builder $query): Builder => $query->orWhereBetweenColumns('value', ['low', 'high']), $betweenColumns],
            'whereNotBetween' => [static fn (Builder $query): Builder => $query->whereNotBetween('value', [1, 5]), $where('("value" < ? or "value" > ?)')],
            'whereNotBetweenColumns' => [static fn (Builder $query): Builder => $query->whereNotBetweenColumns('value', ['low', 'high']), $betweenColumns],
            'orWhereNotBetween' => [static fn (Builder $query): Builder => $query->where('region', 'eu')->orWhereNotBetween('value', [1, 5]), $or('("value" < ? or "value" > ?)')],
            'orWhereNotBetweenColumns' => [static fn (Builder $query): Builder => $query->orWhereNotBetweenColumns('value', ['low', 'high']), $betweenColumns],
            'whereValueBetween' => [static fn (Builder $query): Builder => $query->whereValueBetween(5, ['low', 'high']), $valueBetween],
            'orWhereValueBetween' => [static fn (Builder $query): Builder => $query->orWhereValueBetween(5, ['low', 'high']), $valueBetween],
            'whereValueNotBetween' => [static fn (Builder $query): Builder => $query->whereValueNotBetween(5, ['low', 'high']), $valueBetween],
            'orWhereValueNotBetween' => [static fn (Builder $query): Builder => $query->orWhereValueNotBetween(5, ['low', 'high']), $valueBetween],
            'orWhereNotNull' => [static fn (Builder $query): Builder => $query->where('host', 'web1')->orWhereNotNull('host'), $isNotNull],
            'whereDate' => [static fn (Builder $query): Builder => $query->whereDate('time', '2024-01-01'), $part('date')],
            'orWhereDate' => [static fn (Builder $query): Builder => $query->orWhereDate('time', '2024-01-01'), $part('date')],
            'whereTime' => [static fn (Builder $query): Builder => $query->whereTime('time', '12:00:00'), $part('time')],
            'orWhereTime' => [static fn (Builder $query): Builder => $query->orWhereTime('time', '12:00:00'), $part('time')],
            'whereDay' => [static fn (Builder $query): Builder => $query->whereDay('time', 1), $part('day')],
            'orWhereDay' => [static fn (Builder $query): Builder => $query->orWhereDay('time', 1), $part('day')],
            'whereMonth' => [static fn (Builder $query): Builder => $query->whereMonth('time', 1), $part('month')],
            'orWhereMonth' => [static fn (Builder $query): Builder => $query->orWhereMonth('time', 1), $part('month')],
            'whereYear' => [static fn (Builder $query): Builder => $query->whereYear('time', 2024), $part('year')],
            'orWhereYear' => [static fn (Builder $query): Builder => $query->orWhereYear('time', 2024), $part('year')],
            'whereNested' => [static fn (Builder $query): Builder => $query->whereNested(static fn (Builder $query): Builder => $query->where('host', 'web1')->orWhere('host', 'web2')), $where('("host" = ? or "host" = ?)')],
            'whereExists' => [static fn (Builder $query): Builder => $query->whereExists($sub), $exists],
            'orWhereExists' => [static fn (Builder $query): Builder => $query->orWhereExists($sub), $exists],
            'whereNotExists' => [static fn (Builder $query): Builder => $query->whereNotExists($sub), $notExists],
            'orWhereNotExists' => [static fn (Builder $query): Builder => $query->orWhereNotExists($sub), $notExists],
            'whereRowValues' => [static fn (Builder $query): Builder => $query->whereRowValues(['host', 'region'], '=', ['web1', 'eu']), $rowValues],
            'orWhereRowValues' => [static fn (Builder $query): Builder => $query->orWhereRowValues(['host', 'region'], '=', ['web1', 'eu']), $rowValues],
            'whereJsonContains' => [static fn (Builder $query): Builder => $query->whereJsonContains('tags', 'web'), $jsonContains],
            'orWhereJsonContains' => [static fn (Builder $query): Builder => $query->orWhereJsonContains('tags', 'web'), $jsonContains],
            'whereJsonDoesntContain' => [static fn (Builder $query): Builder => $query->whereJsonDoesntContain('tags', 'web'), $jsonContains],
            'orWhereJsonDoesntContain' => [static fn (Builder $query): Builder => $query->orWhereJsonDoesntContain('tags', 'web'), $jsonContains],
            'whereJsonOverlaps' => [static fn (Builder $query): Builder => $query->whereJsonOverlaps('tags', ['web']), $jsonOverlaps],
            'orWhereJsonOverlaps' => [static fn (Builder $query): Builder => $query->orWhereJsonOverlaps('tags', ['web']), $jsonOverlaps],
            'whereJsonDoesntOverlap' => [static fn (Builder $query): Builder => $query->whereJsonDoesntOverlap('tags', ['web']), $jsonOverlaps],
            'orWhereJsonDoesntOverlap' => [static fn (Builder $query): Builder => $query->orWhereJsonDoesntOverlap('tags', ['web']), $jsonOverlaps],
            'whereJsonContainsKey' => [static fn (Builder $query): Builder => $query->whereJsonContainsKey('tags->web'), $jsonContainsKey],
            'orWhereJsonContainsKey' => [static fn (Builder $query): Builder => $query->orWhereJsonContainsKey('tags->web'), $jsonContainsKey],
            'whereJsonDoesntContainKey' => [static fn (Builder $query): Builder => $query->whereJsonDoesntContainKey('tags->web'), $jsonContainsKey],
            'orWhereJsonDoesntContainKey' => [static fn (Builder $query): Builder => $query->orWhereJsonDoesntContainKey('tags->web'), $jsonContainsKey],
            'whereJsonLength' => [static fn (Builder $query): Builder => $query->whereJsonLength('tags', 2), $jsonLength],
            'orWhereJsonLength' => [static fn (Builder $query): Builder => $query->orWhereJsonLength('tags', 2), $jsonLength],
            'whereFullText' => [static fn (Builder $query): Builder => $query->whereFullText('message', 'error'), $fullText],
            'orWhereFullText' => [static fn (Builder $query): Builder => $query->orWhereFullText('message', 'error'), $fullText],
            'whereAll' => [static fn (Builder $query): Builder => $query->whereAll(['host', 'region'], 'web1'), $where('("host" = ? and "region" = ?)')],
            'orWhereAll' => [static fn (Builder $query): Builder => $query->where('region', 'eu')->orWhereAll(['host', 'region'], 'web1'), $or('("host" = ? and "region" = ?)')],
            'whereAny' => [static fn (Builder $query): Builder => $query->whereAny(['host', 'region'], 'web1'), $where('("host" = ? or "region" = ?)')],
            'orWhereAny' => [static fn (Builder $query): Builder => $query->where('region', 'eu')->orWhereAny(['host', 'region'], 'web1'), $or('("host" = ? or "region" = ?)')],
            'whereNone' => [static fn (Builder $query): Builder => $query->whereNone(['host', 'region'], 'web1'), $not],
            'orWhereNone' => [static fn (Builder $query): Builder => $query->orWhereNone(['host', 'region'], 'web1'), $not],
            'wherePast' => [static fn (Builder $query): Builder => $query->wherePast('time'), $where('"time" < ?')],
            'whereNowOrPast' => [static fn (Builder $query): Builder => $query->whereNowOrPast('time'), $where('"time" <= ?')],
            'orWherePast' => [static fn (Builder $query): Builder => $query->where('region', 'eu')->orWherePast('time'), $or('"time" < ?')],
            'orWhereNowOrPast' => [static fn (Builder $query): Builder => $query->where('region', 'eu')->orWhereNowOrPast('time'), $or('"time" <= ?')],
            'whereFuture' => [static fn (Builder $query): Builder => $query->whereFuture('time'), $where('"time" > ?')],
            'whereNowOrFuture' => [static fn (Builder $query): Builder => $query->whereNowOrFuture('time'), $where('"time" >= ?')],
            'orWhereFuture' => [static fn (Builder $query): Builder => $query->where('region', 'eu')->orWhereFuture('time'), $or('"time" > ?')],
            'orWhereNowOrFuture' => [static fn (Builder $query): Builder => $query->where('region', 'eu')->orWhereNowOrFuture('time'), $or('"time" >= ?')],
            'whereToday' => [static fn (Builder $query): Builder => $query->whereToday('time'), $part('date')],
            'whereBeforeToday' => [static fn (Builder $query): Builder => $query->whereBeforeToday('time'), $part('date')],
            'whereTodayOrBefore' => [static fn (Builder $query): Builder => $query->whereTodayOrBefore('time'), $part('date')],
            'whereAfterToday' => [static fn (Builder $query): Builder => $query->whereAfterToday('time'), $part('date')],
            'whereTodayOrAfter' => [static fn (Builder $query): Builder => $query->whereTodayOrAfter('time'), $part('date')],
            'orWhereToday' => [static fn (Builder $query): Builder => $query->orWhereToday('time'), $part('date')],
            'orWhereBeforeToday' => [static fn (Builder $query): Builder => $query->orWhereBeforeToday('time'), $part('date')],
            'orWhereTodayOrBefore' => [static fn (Builder $query): Builder => $query->orWhereTodayOrBefore('time'), $part('date')],
            'orWhereAfterToday' => [static fn (Builder $query): Builder => $query->orWhereAfterToday('time'), $part('date')],
            'orWhereTodayOrAfter' => [static fn (Builder $query): Builder => $query->orWhereTodayOrAfter('time'), $part('date')],
        ];
    }

    public function testExistsAsksForOnePointAndSaysWhetherItCameBack(): void
    {
        $this->responses->append(
            self::seriesResponse([self::series('cpu', ['time', 'usage_user'], [['2024-01-01T00:00:00Z', 0.64]])]),
            self::emptyResponse(),
        );

        $connection = DB::connection('influxql');

        $this->assertTrue($connection->table('cpu')->beforeQuery(static fn (Builder $query): Builder => $query->where('host', 'web1'))->exists());
        $this->assertTrue($connection->table('cpu')->where('host', 'web2')->latest()->doesntExist());
        $this->assertSame([
            'select * from "cpu" where "host" = \'web1\' limit 1',
            'select * from "cpu" where "host" = \'web2\' order by "time" desc limit 1',
        ], $this->statements());
    }

    public function testCountTakesTheFirstFieldsCountWhenItCountsEveryField(): void
    {
        $this->responses->append(
            self::seriesResponse([self::series('cpu', ['time', 'count_usage_system', 'count_usage_user'], [['1970-01-01T00:00:00Z', 4, 3]])]),
            self::seriesResponse([self::series('cpu', ['time', 'aggregate'], [['1970-01-01T00:00:00Z', 3]])]),
        );

        $connection = DB::connection('influxql');

        $this->assertSame(4, $connection->table('cpu')->where('host', 'web1')->count());
        $this->assertSame(3, $connection->table('cpu')->count('cpu.usage_user'));
        $this->assertSame([
            'select count(*) from "cpu" where "host" = \'web1\'',
            'select count("usage_user") as "aggregate" from "cpu"',
        ], $this->statements());
    }

    public function testTheAggregatesLeaveOutTheColumnsAndTheOrderOfTheQuery(): void
    {
        $aggregate = static fn (float $value): Response => self::seriesResponse([self::series('cpu', ['time', 'aggregate'], [['1970-01-01T00:00:00Z', $value]])]);

        $this->responses->append($aggregate(0.9), $aggregate(0.1), $aggregate(2.5), $aggregate(0.5));

        $query = DB::connection('influxql')->table('cpu')->select('host', 'usage_user')->where('host', 'web1')->latest();

        $this->assertSame(0.9, $query->max('usage_user'));
        $this->assertSame(0.1, $query->min('usage_user'));
        $this->assertSame(2.5, $query->sum('usage_user'));
        $this->assertSame(0.5, $query->avg('usage_user'));
        $this->assertSame([
            'select max("usage_user") as "aggregate" from "cpu" where "host" = \'web1\'',
            'select min("usage_user") as "aggregate" from "cpu" where "host" = \'web1\'',
            'select sum("usage_user") as "aggregate" from "cpu" where "host" = \'web1\'',
            'select mean("usage_user") as "aggregate" from "cpu" where "host" = \'web1\'',
        ], $this->statements());
    }

    public function testAnAggregateOfNoPointsIsNullOrZero(): void
    {
        $this->responses->append(self::emptyResponse(), self::emptyResponse(), self::emptyResponse());

        $query = DB::connection('influxql')->table('cpu');

        $this->assertNull($query->max('usage_user'));
        $this->assertSame(0, $query->sum('usage_user'));
        $this->assertSame(0, $query->count());
    }

    public function testValueReadsTheFieldItAskedForRatherThanTheTime(): void
    {
        $point = static fn (string $field, float $value): Response => self::seriesResponse([self::series('cpu', ['time', $field], [['2024-01-01T00:00:00Z', $value]])]);

        $this->responses->append($point('usage_user', 0.64), $point('u', 0.64), $point('usage_user', 0.64), $point('mean', 0.5), self::emptyResponse());

        $connection = DB::connection('influxql');

        $this->assertSame(0.64, $connection->table('cpu')->value('cpu.usage_user'));
        $this->assertSame(0.64, $connection->table('cpu')->value('usage_user as u'));
        $this->assertSame(0.64, $connection->table('cpu')->value('usage_user::field'));
        $this->assertSame(0.5, $connection->table('cpu')->value(new Expression('mean("usage_user")')));
        $this->assertNull($connection->table('cpu')->value('usage_user'));
        $this->assertSame([
            'select "usage_user" from "cpu" limit 1',
            'select "usage_user" as "u" from "cpu" limit 1',
            'select "usage_user"::field from "cpu" limit 1',
            'select mean("usage_user") from "cpu" limit 1',
            'select "usage_user" from "cpu" limit 1',
        ], $this->statements());
    }

    public function testRawValueAndSoleValueReadAsValueDoes(): void
    {
        $this->responses->append(
            self::seriesResponse([self::series('cpu', ['time', 'mean'], [['1970-01-01T00:00:00Z', 0.5]])]),
            self::seriesResponse([self::series('cpu', ['time', 'usage_user'], [['2024-01-01T00:00:00Z', 0.64]])]),
        );

        $connection = DB::connection('influxql');

        $this->assertSame(0.5, $connection->table('cpu')->rawValue('mean("usage_user")'));
        $this->assertSame(0.64, $connection->table('cpu')->where('host', 'web1')->soleValue('usage_user'));
        $this->assertSame([
            'select mean("usage_user") from "cpu" limit 1',
            'select "usage_user" from "cpu" where "host" = \'web1\' limit 2',
        ], $this->statements());
    }

    public function testPluckReadsTheFieldsItAskedFor(): void
    {
        $this->responses->append(
            self::seriesResponse([self::series('cpu', ['time', 'usage_user', 'host'], [['2024-01-01T00:00:00Z', 0.5, 'web1'], ['2024-01-01T00:00:10Z', 0.25, 'web2']])]),
            self::seriesResponse([self::series('cpu', ['time', 'u'], [['2024-01-01T00:00:00Z', 0.5], ['2024-01-01T00:00:10Z', 0.25]])]),
            self::seriesResponse([self::series('cpu', ['time', 'usage_user'], [['2024-01-01T00:00:00Z', 0.5]])]),
            self::emptyResponse(),
        );

        $connection = DB::connection('influxql');

        $this->assertSame(['web1' => 0.5, 'web2' => 0.25], $connection->table('cpu')->pluck('cpu.usage_user', 'cpu.host')->all());
        $this->assertSame([0.5, 0.25], $connection->table('cpu')->pluck('usage_user as u')->all());
        $this->assertSame([0.5], $connection->table('cpu')->pluck('usage_user::field')->all());
        $this->assertSame([], $connection->table('cpu')->pluck('usage_user')->all());
        $this->assertSame([
            'select "usage_user", "host" from "cpu"',
            'select "usage_user" as "u" from "cpu"',
            'select "usage_user"::field from "cpu"',
            'select "usage_user" from "cpu"',
        ], $this->statements());
    }

    public function testPluckReadsARawExpressionFromTheFirstFieldAfterTheTime(): void
    {
        $this->responses->append(
            self::seriesResponse([self::series('cpu', ['time', 'mean'], [['2024-01-01T00:00:00Z', 0.5], ['2024-01-01T01:00:00Z', 0.25]])]),
            self::seriesResponse([
                self::series('cpu', ['time', 'max', 'host'], [['2024-01-01T00:10:00Z', 0.9, 'web1']], ['region' => 'eu']),
                self::series('cpu', ['time', 'max', 'host'], [['2024-01-01T00:20:00Z', 0.8, 'web3']], ['region' => 'us']),
            ]),
            self::emptyResponse(),
        );

        $connection = DB::connection('influxql');

        [$means, $field] = $connection->table('cpu')->whereRaw('time > now() - 2h')->groupByTime('1h')->pluckWithColumn(new Expression('mean("usage_user")'));

        $this->assertSame([0.5, 0.25], $means->all());
        $this->assertSame('mean', $field, 'Eloquent casts the values by the name the server gave the field.');
        $this->assertSame(['web1' => 0.9, 'web3' => 0.8], $connection->table('cpu')->groupBy('region')->pluck(new Expression('max("usage_user")'), 'host')->all());

        [$none, $noField] = $connection->table('cpu')->pluckWithColumn(new Expression('mean("usage_user")'));

        $this->assertSame([], $none->all());
        $this->assertNull($noField);
        $this->assertSame([
            'select mean("usage_user") from "cpu" where time > now() - 2h group by time(1h)',
            'select max("usage_user"), "host" from "cpu" group by "region"',
            'select mean("usage_user") from "cpu"',
        ], $this->statements());
    }

    public function testPaginateCountsTheFirstFieldThenReadsThePage(): void
    {
        $this->responses->append(
            self::seriesResponse([self::series('cpu', ['time', 'count_usage_user'], [['1970-01-01T00:00:00Z', 5]])]),
            self::seriesResponse([self::series('cpu', ['time', 'usage_user'], [['2024-01-01T00:00:20Z', 0.3], ['2024-01-01T00:00:10Z', 0.2]])]),
        );

        $page = DB::connection('influxql')->table('cpu')
            ->select('usage_user')
            ->beforeQuery(static fn (Builder $query): Builder => $query->where('host', 'web1'))
            ->latest()
            ->paginate(2, page: 2);

        $this->assertSame(5, $page->total());
        $this->assertSame([0.3, 0.2], array_column($page->items(), 'usage_user'));
        $this->assertSame([
            'select count(*) from "cpu" where "host" = \'web1\'',
            'select "usage_user" from "cpu" where "host" = \'web1\' order by "time" desc limit 2 offset 2',
        ], $this->statements());
    }

    public function testPaginateSendsNoPageWhenThereAreNoPoints(): void
    {
        $this->responses->append(self::emptyResponse());

        $this->assertSame(0, DB::connection('influxql')->table('cpu')->paginate(2, page: 1)->total());
        $this->assertSame(['select count(*) from "cpu"'], $this->statements());
    }

    public function testPaginateCannotCountTheGroupsOfAGroupedQuery(): void
    {
        try {
            DB::connection('influxql')->table('cpu')->selectRaw('mean("usage_user")')->groupByTime('1h')->paginate(2, page: 1);

            $this->fail('No exception was thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('InfluxQL cannot count the groups of a grouped query; pass paginate() its total instead.', $exception->getMessage());
            $this->assertSame([], $this->history);
        }
    }

    public function testAGroupedQueryPaginatesOnTheTotalItIsGiven(): void
    {
        $this->responses->append(self::seriesResponse([self::series('cpu', ['time', 'mean'], [['2024-01-01T00:00:00Z', 0.5], ['2024-01-01T01:00:00Z', 0.25]])]));

        $page = DB::connection('influxql')->table('cpu')->selectRaw('mean("usage_user") as "mean"')->groupByTime('1h')->paginate(2, page: 1, total: 24);

        $this->assertSame(24, $page->total());
        $this->assertSame([0.5, 0.25], array_column($page->items(), 'mean'));
        $this->assertSame(['select mean("usage_user") as "mean" from "cpu" group by time(1h) limit 2 offset 0'], $this->statements());
    }

    public function testCursorPaginationPagesByTime(): void
    {
        $this->responses->append(
            self::seriesResponse([self::series('cpu', ['time', 'usage_user'], [
                ['2024-01-01T00:00:00Z', 0.1],
                ['2024-01-01T00:00:10Z', 0.2],
                ['2024-01-01T00:00:20Z', 0.3],
            ])]),
            self::seriesResponse([self::series('cpu', ['time', 'usage_user'], [['2024-01-01T00:00:20Z', 0.3]])]),
        );

        $connection = DB::connection('influxql');

        $first = $connection->table('cpu')->cursorPaginate(2);
        $second = $connection->table('cpu')->cursorPaginate(2, cursor: $first->nextCursor());

        $this->assertSame([0.1, 0.2], array_column($first->items(), 'usage_user'));
        $this->assertSame([0.3], array_column($second->items(), 'usage_user'));
        $this->assertSame([
            'select * from "cpu" order by "time" asc limit 3',
            'select * from "cpu" where ("time" > \'2024-01-01T00:00:10Z\') order by "time" asc limit 3',
        ], $this->statements());
    }

    public function testChunkPagesThroughThePointsInTimeOrder(): void
    {
        $this->responses->append(
            self::seriesResponse([self::series('cpu', ['time', 'usage_user'], [['2024-01-01T00:00:00Z', 0.1], ['2024-01-01T00:00:10Z', 0.2]])]),
            self::seriesResponse([self::series('cpu', ['time', 'usage_user'], [['2024-01-01T00:00:20Z', 0.3]])]),
        );

        $chunks = [];

        $completed = DB::connection('influxql')->table('cpu')->where('host', 'web1')->chunk(2, static function (Collection $rows, int $page) use (&$chunks): void {
            $chunks[$page] = $rows->pluck('usage_user')->all();
        });

        $this->assertTrue($completed);
        $this->assertSame([1 => [0.1, 0.2], 2 => [0.3]], $chunks);
        $this->assertSame([
            'select * from "cpu" where "host" = \'web1\' order by "time" asc limit 2 offset 0',
            'select * from "cpu" where "host" = \'web1\' order by "time" asc limit 2 offset 2',
        ], $this->statements());
    }

    public function testLazyKeepsTheOrderTheQueryHas(): void
    {
        $this->responses->append(
            self::seriesResponse([self::series('cpu', ['time', 'usage_user'], [['2024-01-01T00:00:20Z', 0.3], ['2024-01-01T00:00:10Z', 0.2]])]),
            self::seriesResponse([self::series('cpu', ['time', 'usage_user'], [['2024-01-01T00:00:00Z', 0.1]])]),
        );

        $this->assertSame([0.3, 0.2, 0.1], DB::connection('influxql')->table('cpu')->latest()->lazy(2)->pluck('usage_user')->all());
        $this->assertSame([
            'select * from "cpu" order by "time" desc limit 2 offset 0',
            'select * from "cpu" order by "time" desc limit 2 offset 2',
        ], $this->statements());
    }

    public function testSeriesReturnsThePointsAsTheServerGroupedThem(): void
    {
        $this->responses->append(self::seriesResponse([
            self::series('cpu', ['time', 'mean'], [['2024-01-01T00:00:00Z', 0.5]], ['host' => 'web1']),
            self::series('cpu', ['time', 'mean'], [['2024-01-01T00:00:00Z', 0.25]], ['host' => 'web2']),
        ]));

        $series = DB::connection('influxql')->table('cpu')
            ->selectRaw('mean("usage_user") as "mean"')
            ->where('region', 'eu')
            ->groupBy('host')
            ->beforeQuery(static fn (Builder $query): Builder => $query->groupByTime('1h'))
            ->series();

        $this->assertCount(2, $series);
        $this->assertContainsOnlyInstancesOf(Series::class, $series);
        $this->assertSame(['host' => 'web2'], $series[1]->tags);
        $this->assertSame([['2024-01-01T00:00:00Z', 0.25]], $series[1]->values);
        $this->assertSame(['select mean("usage_user") as "mean" from "cpu" where "region" = \'eu\' group by "host", time(1h)'], $this->statements());
    }

    /**
     * A query on a measurement, on a connection to the given version.
     */
    private function table(string $measurement = 'cpu', Version $version = Version::V1, ?string $retentionPolicy = null): Builder
    {
        return $this->builder($version, $retentionPolicy)->from($measurement);
    }

    /**
     * A query on no measurement yet, on a connection to the given version.
     *
     * The connection is the double GrammarTest's builder() describes.
     */
    private function builder(Version $version = Version::V1, ?string $retentionPolicy = null): Builder
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('prepareBindings')->andReturnUsing(static fn (array $bindings): array => $bindings);
        $connection->shouldReceive('getDatabaseName')->andReturn('telegraf');

        return new Builder($connection, new Grammar($connection, $version, $retentionPolicy), new Processor);
    }
}
