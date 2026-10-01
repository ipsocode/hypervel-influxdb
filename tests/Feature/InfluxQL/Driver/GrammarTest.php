<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature\InfluxQL\Driver;

use Closure;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Hypervel\Contracts\Database\Query\Expression as ExpressionContract;
use Hypervel\Database\Connection;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\Expression;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Foundation\Testing\Attributes\UnitTest;
use InvalidArgumentException;
use Ipsocode\InfluxDB\InfluxQL\Dialect;
use Ipsocode\InfluxDB\InfluxQL\Driver\Grammar;
use Ipsocode\InfluxDB\InfluxQL\Expression as InfluxQLExpression;
use Ipsocode\InfluxDB\InfluxQL\Regex;
use Ipsocode\InfluxDB\InfluxQL\Version;
use Ipsocode\InfluxDB\Tests\TestCase;
use LogicException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Every clause the `influxql` driver's grammar compiles, the literals it embeds, and what it refuses.
 *
 * Nothing is sent; clauses compile alike on every version, so they are shown on 1.x, and what a version refuses comes last.
 */
class GrammarTest extends TestCase
{
    #[UnitTest]
    public function testAQueryWithNoColumnsSelectsEverything(): void
    {
        $this->assertSame('select * from "cpu"', $this->table()->toSql());
    }

    #[UnitTest]
    public function testColumnsAreDoubleQuotedAndTheWildcardIsNot(): void
    {
        $this->assertSame('select "usage_user", "host" from "cpu"', $this->table()->select('usage_user', 'host')->toSql());
        $this->assertSame('select "usage_user", "host" from "cpu"', $this->table()->select(['usage_user', 'host'])->toSql());
        $this->assertSame('select *, "host" from "cpu"', $this->table()->select('*', 'host')->toSql());
    }

    #[UnitTest]
    public function testAColumnIsQuotedWholeRatherThanSplitOnDots(): void
    {
        $this->assertSame('select "disk.used" from "cpu"', $this->table()->select('disk.used')->toSql());
    }

    #[UnitTest]
    public function testAnAliasIsQuotedOnBothSidesOfAs(): void
    {
        $this->assertSame(
            'select "usage_user" as "user", "usage_system" as "system" from "cpu"',
            $this->table()->select('usage_user as user', 'usage_system AS system')->toSql(),
        );
    }

    #[UnitTest]
    public function testATypeHintStaysOutsideTheQuotes(): void
    {
        $this->assertSame(
            'select "host"::tag, "value"::field, "load"::float, "count"::integer from "cpu"',
            $this->table()->select('host::tag', 'value::field', 'load::FLOAT', 'count::integer')->toSql(),
        );
        $this->assertSame('select "value"::field as "v" from "cpu"', $this->table()->select('value::field as v')->toSql());
    }

    #[UnitTest]
    public function testIdentifiersEscapeQuotesBackslashesAndNewlinesWithABackslash(): void
    {
        $this->assertSame(
            'select "a\"b", "c\\\d", "e\nf" from "cpu"',
            $this->table()->select('a"b', 'c\d', "e\nf")->toSql(),
        );
    }

    #[UnitTest]
    public function testARawSelectIsEmbeddedAsWrittenWithItsBindings(): void
    {
        $query = $this->table()->selectRaw('mean("value") * ? as "scaled"', [2]);

        $this->assertSame('select mean("value") * ? as "scaled" from "cpu"', $query->toSql());
        $this->assertSame('select mean("value") * 2 as "scaled" from "cpu"', $query->toRawSql());
    }

    #[UnitTest]
    public function testDistinctWrapsEachColumnInTheDistinctFunctionAndKeepsTheAliasOutside(): void
    {
        $this->assertSame('select distinct("host") from "cpu"', $this->table()->select('host')->distinct()->toSql());
        $this->assertSame('select distinct("value") as "v" from "cpu"', $this->table()->select('value as v')->distinct()->toSql());
        $this->assertSame('select distinct(*) from "cpu"', $this->table()->distinct()->toSql());
        $this->assertSame('select distinct("value"::field) from "cpu"', $this->table()->select(new Expression('"value"::field'))->distinct()->toSql());
    }

    #[UnitTest]
    public function testDistinctOnColumnsIsRefused(): void
    {
        $query = $this->table()->select('host', 'value')->distinct('host');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('InfluxQL has no DISTINCT ON; call distinct() without columns, and it applies to each selected field.');

        $query->toSql();
    }

    #[UnitTest]
    public function testADottedMeasurementIsQualifiedSegmentBySegment(): void
    {
        $this->assertSame('select * from "telegraf"."autogen"."cpu"', $this->table('telegraf.autogen.cpu')->toSql());
        $this->assertSame('select * from "autogen"."cpu"', $this->table('autogen.cpu')->toSql());
        $this->assertSame('select * from "telegraf".."cpu"', $this->table('telegraf..cpu')->toSql());
    }

    #[UnitTest]
    public function testAMeasurementWhoseNameHasADotIsAnExpression(): void
    {
        $this->assertSame('select * from "disk.io"', $this->builder()->from(new Expression('"disk.io"'))->toSql());
    }

    #[UnitTest]
    public function testMeasurementIdentifiersAreEscaped(): void
    {
        $this->assertSame('select * from "c\"p\\\u"', $this->table('c"p\u')->toSql());
    }

    /**
     * InfluxDB has no table prefix; the grammar never asks the connection for one.
     */
    #[UnitTest]
    public function testATablePrefixIsNotApplied(): void
    {
        $this->assertSame('"cpu"', $this->grammar()->wrapTable('cpu', 'pre_'));
        $this->assertSame('"telegraf"."autogen"."cpu"', $this->grammar()->wrapTable('telegraf.autogen.cpu', 'pre_'));
    }

    #[UnitTest]
    public function testAnAliasedMeasurementIsRefused(): void
    {
        $query = $this->builder()->from('cpu', 'c');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('InfluxQL cannot alias a measurement, as [cpu as c] does; name the measurement alone.');

        $query->toSql();
    }

    #[UnitTest]
    public function testARegexMeasurementIsABindingRenderedAsARegexLiteral(): void
    {
        $query = $this->builder()->fromRaw('?', [new Regex('^(cpu|mem)$')]);

        $this->assertSame('select * from ?', $query->toSql());
        $this->assertSame('select * from /^(cpu|mem)$/', $query->toRawSql());
    }

    #[UnitTest]
    public function testARawFromIsEmbeddedAsWrittenWithItsBindings(): void
    {
        $this->assertSame('select * from "cpu", "mem"', $this->builder()->fromRaw('"cpu", "mem"')->toRawSql());
        $this->assertSame('select * from "cpu"', $this->builder()->fromRaw(new Expression('"cpu"'))->toRawSql());
    }

    #[UnitTest]
    public function testASubqueryIsARawFromWithItsBindings(): void
    {
        $inner = $this->table()->selectRaw('mean("value") as "mean"')->where('host', 'web1')->groupByRaw('time(10m)');

        $query = $this->builder()->select('mean')->fromRaw('(' . $inner->toSql() . ')', $inner->getBindings());

        $this->assertSame('select "mean" from (select mean("value") as "mean" from "cpu" where "host" = ? group by time(10m))', $query->toSql());
        $this->assertSame('select "mean" from (select mean("value") as "mean" from "cpu" where "host" = \'web1\' group by time(10m))', $query->toRawSql());
    }

    #[UnitTest]
    public function testIntoComesBetweenTheColumnsAndFrom(): void
    {
        $query = $this->table()->selectRaw('mean("value")')->groupByRaw('time(1h)');
        $query->into = 'telegraf.autogen.cpu_hourly';

        $this->assertSame('select mean("value") into "telegraf"."autogen"."cpu_hourly" from "cpu" group by time(1h)', $query->toSql());
    }

    #[UnitTest]
    public function testAnIntoExpressionCarriesAMeasurementBackreference(): void
    {
        $query = $this->builder()->selectRaw('mean(*)')->fromRaw('?', [new Regex('.*')])->groupByRaw('time(1h)');
        $query->into = new Expression('"downsampled"."autogen".:MEASUREMENT');

        $this->assertSame('select mean(*) into "downsampled"."autogen".:MEASUREMENT from /.*/ group by time(1h)', $query->toRawSql());
    }

    #[UnitTest]
    public function testABasicWhereComparesAColumnWithABinding(): void
    {
        $query = $this->table()->where('host', 'web1');

        $this->assertSame('select * from "cpu" where "host" = ?', $query->toSql());
        $this->assertSame(['web1'], $query->getBindings());
        $this->assertSame('select * from "cpu" where "host" = \'web1\'', $query->toRawSql());
    }

    #[UnitTest]
    #[DataProvider('operators')]
    public function testEveryComparisonOperatorCompilesAsWritten(string $operator): void
    {
        $this->assertSame(
            "select * from \"cpu\" where \"value\" {$operator} 5",
            $this->table()->where('value', $operator, 5)->toRawSql(),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function operators(): array
    {
        return array_combine(
            ['=', '<', '>', '<=', '>=', '<>', '!='],
            [['='], ['<'], ['>'], ['<='], ['>='], ['<>'], ['!=']],
        );
    }

    #[UnitTest]
    public function testTheRegexOperatorsTakeARegexLiteral(): void
    {
        $query = $this->table()->where('host', '=~', new Regex('^web'));

        $this->assertSame('select * from "cpu" where "host" =~ ?', $query->toSql());
        $this->assertSame('select * from "cpu" where "host" =~ /^web/', $query->toRawSql());
        $this->assertSame('select * from "cpu" where "host" !~ /^web/', $this->table()->where('host', '!~', new Regex('^web'))->toRawSql());
    }

    #[UnitTest]
    public function testWheresChainWithAndAndOr(): void
    {
        $this->assertSame(
            'select * from "cpu" where "host" = \'web1\' and "value" > 5 or "region" = \'eu\'',
            $this->table()->where('host', 'web1')->where('value', '>', 5)->orWhere('region', 'eu')->toRawSql(),
        );
        $this->assertSame(
            'select * from "cpu" where "value" > 5 or "value" < 1',
            $this->table()->where('value', '>', 5)->orWhere('value', '<', 1)->toRawSql(),
        );
    }

    #[UnitTest]
    public function testAClosureStartsANestedGroup(): void
    {
        $query = $this->table()
            ->where(function (Builder $query): void {
                $query->where('host', 'web1')->orWhere('host', 'web2');
            })
            ->where('value', '>', 5)
            ->orWhere(function (Builder $query): void {
                $query->where('region', 'eu');
            });

        $this->assertSame('select * from "cpu" where ("host" = ? or "host" = ?) and "value" > ? or ("region" = ?)', $query->toSql());
        $this->assertSame(['web1', 'web2', 5, 'eu'], $query->getBindings());
    }

    #[UnitTest]
    public function testAnEmptyNestedGroupIsLeftOut(): void
    {
        $this->assertSame('select * from "cpu"', $this->table()->where(function (Builder $query): void {})->toSql());
    }

    #[UnitTest]
    public function testAnArrayOfWheresIsANestedGroupOfEqualities(): void
    {
        $this->assertSame(
            'select * from "cpu" where ("host" = \'web1\' and "region" = \'eu\')',
            $this->table()->where(['host' => 'web1', 'region' => 'eu'])->toRawSql(),
        );
    }

    #[UnitTest]
    public function testAListOfWheresPassesEachEntryAsArguments(): void
    {
        $this->assertSame(
            'select * from "cpu" where ("value" > 5 and "host" = \'web1\')',
            $this->table()->where([['value', '>', 5], ['host', 'web1']])->toRawSql(),
        );
    }

    #[UnitTest]
    public function testAnOrArrayOfWheresOrsItsEntriesToo(): void
    {
        $this->assertSame(
            'select * from "cpu" where "value" > 5 or ("host" = \'web1\' or "host" = \'web2\')',
            $this->table()->where('value', '>', 5)->orWhere([['host', 'web1'], ['host', '=', 'web2']])->toRawSql(),
        );
    }

    #[UnitTest]
    public function testAnExpressionValueIsEmbeddedAsWrittenWithoutABinding(): void
    {
        $query = $this->table()->where('time', '>', new Expression('now() - 1h'));

        $this->assertSame('select * from "cpu" where "time" > now() - 1h', $query->toSql());
        $this->assertSame([], $query->getBindings());
    }

    #[UnitTest]
    public function testAnExpressionColumnIsEmbeddedAsWritten(): void
    {
        $this->assertSame(
            'select * from "cpu" where "usage_user" + "usage_system" > 90',
            $this->table()->where(new Expression('"usage_user" + "usage_system"'), '>', 90)->toRawSql(),
        );
    }

    #[UnitTest]
    public function testWhereColumnComparesTwoColumns(): void
    {
        $this->assertSame('select * from "cpu" where "usage_user" > "usage_system"', $this->table()->whereColumn('usage_user', '>', 'usage_system')->toSql());
        $this->assertSame('select * from "cpu" where "a" = "b"', $this->table()->whereColumn('a', 'b')->toSql());
        $this->assertSame('select * from "cpu" where "a" = "b" or "c" < "d"', $this->table()->whereColumn('a', 'b')->orWhereColumn('c', '<', 'd')->toSql());
    }

    #[UnitTest]
    public function testAnArrayOfColumnComparisonsIsANestedGroup(): void
    {
        $this->assertSame(
            'select * from "cpu" where ("a" = "b" and "c" > "d")',
            $this->table()->whereColumn([['a', 'b'], ['c', '>', 'd']])->toSql(),
        );
        $this->assertSame('select * from "cpu" where ("a" = "b")', $this->table()->whereColumn(['a' => 'b'])->toSql());
    }

    #[UnitTest]
    public function testARawWhereIsEmbeddedAsWrittenWithItsBindings(): void
    {
        $query = $this->table()->whereRaw('"value" > ? and "value" < ?', [1, 10])->orWhereRaw('"host" = ?', 'web1');

        $this->assertSame('select * from "cpu" where "value" > ? and "value" < ? or "host" = ?', $query->toSql());
        $this->assertSame('select * from "cpu" where "value" > 1 and "value" < 10 or "host" = \'web1\'', $query->toRawSql());
        $this->assertSame('select * from "cpu" where time > now() - 1h', $this->table()->whereRaw(new Expression('time > now() - 1h'))->toSql());
    }

    #[UnitTest]
    public function testARawWhereTakesRegexAndDateBindings(): void
    {
        $this->assertSame(
            'select * from "cpu" where "host" =~ /^web/',
            $this->table()->whereRaw('"host" =~ ?', [new Regex('^web')])->toRawSql(),
        );
        $this->assertSame(
            'select * from "cpu" where time > \'2024-01-01T00:00:00.000000Z\'',
            $this->table()->whereRaw('time > ?', [new DateTimeImmutable('2024-01-01T00:00:00Z')])->toRawSql(),
        );
    }

    #[UnitTest]
    public function testWhereInIsTheOrOfOneEqualityPerValue(): void
    {
        $query = $this->table()->whereIn('host', ['web1', 'web2']);

        $this->assertSame('select * from "cpu" where ("host" = ? or "host" = ?)', $query->toSql());
        $this->assertSame('select * from "cpu" where ("host" = \'web1\' or "host" = \'web2\')', $query->toRawSql());
    }

    #[UnitTest]
    public function testWhereNotInIsTheAndOfOneInequalityPerValue(): void
    {
        $this->assertSame(
            'select * from "cpu" where ("host" != \'web1\' and "host" != \'web2\')',
            $this->table()->whereNotIn('host', ['web1', 'web2'])->toRawSql(),
        );
    }

    #[UnitTest]
    public function testAnEmptyWhereInMatchesNothingAndAnEmptyWhereNotInEverything(): void
    {
        $this->assertSame('select * from "cpu" where 0 = 1', $this->table()->whereIn('host', [])->toSql());
        $this->assertSame('select * from "cpu" where 1 = 1', $this->table()->whereNotIn('host', [])->toSql());
    }

    #[UnitTest]
    public function testOrWhereInAndOrWhereNotInJoinWithOr(): void
    {
        $this->assertSame(
            'select * from "cpu" where "value" > 5 or ("host" = \'web1\') or ("region" != \'eu\')',
            $this->table()->where('value', '>', 5)->orWhereIn('host', ['web1'])->orWhereNotIn('region', ['eu'])->toRawSql(),
        );
    }

    #[UnitTest]
    public function testAnExpressionInAWhereInListIsEmbeddedWithoutABinding(): void
    {
        $query = $this->table()->whereIn('value', [1, new Expression('2 + 1')]);

        $this->assertSame('select * from "cpu" where ("value" = ? or "value" = 2 + 1)', $query->toSql());
        $this->assertSame([1], $query->getBindings());
    }

    #[UnitTest]
    public function testWhereBetweenIsTwoInclusiveComparisons(): void
    {
        $query = $this->table()->whereBetween('value', [1, 10]);

        $this->assertSame('select * from "cpu" where ("value" >= ? and "value" <= ?)', $query->toSql());
        $this->assertSame('select * from "cpu" where ("value" >= 1 and "value" <= 10)', $query->toRawSql());
    }

    #[UnitTest]
    public function testWhereNotBetweenIsTwoExclusiveComparisons(): void
    {
        $this->assertSame(
            'select * from "cpu" where ("value" < 1 or "value" > 10)',
            $this->table()->whereNotBetween('value', [1, 10])->toRawSql(),
        );
    }

    #[UnitTest]
    public function testOrWhereBetweenAndOrWhereNotBetweenJoinWithOr(): void
    {
        $this->assertSame(
            'select * from "cpu" where "host" = \'web1\' or ("value" >= 1 and "value" <= 2) or ("value" < 5 or "value" > 6)',
            $this->table()->where('host', 'web1')->orWhereBetween('value', [1, 2])->orWhereNotBetween('value', [5, 6])->toRawSql(),
        );
    }

    #[UnitTest]
    public function testATimeWindowWithDatesIsEmbeddedInUtc(): void
    {
        $this->assertSame(
            'select * from "cpu" where ("time" >= \'2024-01-01T00:00:00.000000Z\' and "time" <= \'2024-01-01T23:59:59.500000Z\')',
            $this->table()->whereBetween('time', [
                new DateTimeImmutable('2024-01-01 02:00:00', new DateTimeZone('+02:00')),
                new DateTime('2024-01-01 18:59:59.5', new DateTimeZone('America/New_York')),
            ])->toRawSql(),
        );
    }

    #[UnitTest]
    public function testAnExpressionBoundIsEmbeddedWithoutABinding(): void
    {
        $query = $this->table()->whereBetween('time', [new Expression('now() - 1h'), new Expression('now()')]);

        $this->assertSame('select * from "cpu" where ("time" >= now() - 1h and "time" <= now())', $query->toSql());
        $this->assertSame([], $query->getBindings());
    }

    #[UnitTest]
    public function testGroupByTakesTagsAndTheWildcard(): void
    {
        $this->assertSame('select * from "cpu" group by "host"', $this->table()->groupBy('host')->toSql());
        $this->assertSame('select * from "cpu" group by "host", "region"', $this->table()->groupBy('host', 'region')->toSql());
        $this->assertSame('select * from "cpu" group by "host", "region", "zone"', $this->table()->groupBy(['host', 'region'])->groupBy('zone')->toSql());
        $this->assertSame('select * from "cpu" group by *', $this->table()->groupBy('*')->toSql());
    }

    #[UnitTest]
    public function testATimeIntervalIsARawGroup(): void
    {
        $this->assertSame('select * from "cpu" group by time(10m)', $this->table()->groupByRaw('time(10m)')->toSql());
        $this->assertSame('select * from "cpu" group by time(1d), "host"', $this->table()->groupByRaw('time(1d)')->groupBy('host')->toSql());
        $this->assertSame('select * from "cpu" group by /^h/', $this->table()->groupByRaw('?', [new Regex('^h')])->toRawSql());
    }

    #[UnitTest]
    #[DataProvider('fills')]
    public function testFillCompilesAKeywordOrANumber(float|int|string $value, string $expected): void
    {
        $query = $this->table()->selectRaw('mean("value")')->groupByRaw('time(1h)');
        $query->fill = $value;

        $this->assertSame("select mean(\"value\") from \"cpu\" group by time(1h) fill({$expected})", $query->toSql());
    }

    /**
     * @return array<string, array{float|int|string, string}>
     */
    public static function fills(): array
    {
        return [
            'none' => ['none', 'none'],
            'null' => ['null', 'null'],
            'previous' => ['previous', 'previous'],
            'linear' => ['linear', 'linear'],
            'zero' => [0, '0'],
            'negative integer' => [-1, '-1'],
            'float' => [0.5, '0.5'],
            'float needing no exponent' => [1.0E+20, '100000000000000000000.0'],
        ];
    }

    #[UnitTest]
    public function testOrderByCompilesTimeInEitherDirection(): void
    {
        $this->assertSame('select * from "cpu" order by "time" asc', $this->table()->orderBy('time')->toSql());
        $this->assertSame('select * from "cpu" order by "time" desc', $this->table()->orderBy('time', 'desc')->toSql());
        $this->assertSame('select * from "cpu" order by "time" desc', $this->table()->orderBy('time', 'DESC')->toSql());
        $this->assertSame('select * from "cpu" order by "time" desc', $this->table()->orderByDesc('time')->toSql());
        $this->assertSame('select * from "cpu" order by "time" desc', $this->table()->latest('time')->toSql());
        $this->assertSame('select * from "cpu" order by "time" asc', $this->table()->oldest('time')->toSql());
        $this->assertSame('select * from "cpu" order by time desc', $this->table()->orderBy(new Expression('time'), 'desc')->toSql());
    }

    #[UnitTest]
    public function testARawOrderIsEmbeddedAsWrittenWithItsBindings(): void
    {
        $this->assertSame('select * from "cpu" order by time desc', $this->table()->orderByRaw('time desc')->toSql());
        $this->assertSame('select * from "cpu" order by time desc', $this->table()->orderByRaw('? desc', [new Expression('time')])->toRawSql());
    }

    #[UnitTest]
    public function testReorderReplacesEveryOrder(): void
    {
        $this->assertSame('select * from "cpu"', $this->table()->orderByDesc('time')->orderByRaw('time desc')->reorder()->toSql());
        $this->assertSame('select * from "cpu" order by "time" desc', $this->table()->orderBy('time')->reorder('time', 'desc')->toSql());
    }

    #[UnitTest]
    public function testLimitAndOffsetCountPoints(): void
    {
        $this->assertSame('select * from "cpu" limit 10 offset 20', $this->table()->limit(10)->offset(20)->toSql());
        $this->assertSame('select * from "cpu" limit 10 offset 20', $this->table()->take(10)->skip(20)->toSql());
        $this->assertSame('select * from "cpu" limit 25 offset 50', $this->table()->forPage(3, 25)->toSql());
        $this->assertSame('select * from "cpu" limit 15 offset 0', $this->table()->forPage(1)->toSql());
    }

    #[UnitTest]
    public function testSlimitAndSoffsetCountSeries(): void
    {
        $query = $this->table()->groupBy('*');
        $query->slimit = 2;
        $query->soffset = 1;

        $this->assertSame('select * from "cpu" group by * slimit 2 soffset 1', $query->toSql());
    }

    #[UnitTest]
    public function testTzLocalisesTheReturnedTimestamps(): void
    {
        $query = $this->table();
        $query->timezone = 'America/Chicago';

        $this->assertSame('select * from "cpu" tz(\'America/Chicago\')', $query->toSql());
    }

    #[UnitTest]
    public function testEveryClauseComesInTheOrderInfluxqlReadsIt(): void
    {
        $query = $this->builder()
            ->offset(5)
            ->limit(10)
            ->orderByDesc('time')
            ->groupBy('region')
            ->groupByRaw('time(1h)')
            ->where('host', 'web1')
            ->where('time', '>=', new Expression('now() - 1d'))
            ->from('telegraf.autogen.cpu')
            ->selectRaw('mean("usage_user") as "mean"');
        $query->timezone = 'Europe/Amsterdam';
        $query->soffset = 1;
        $query->slimit = 2;
        $query->fill = 'previous';
        $query->into = 'telegraf.autogen.cpu_hourly';

        $this->assertSame(
            'select mean("usage_user") as "mean" into "telegraf"."autogen"."cpu_hourly" from "telegraf"."autogen"."cpu"'
            . ' where "host" = \'web1\' and "time" >= now() - 1d group by "region", time(1h) fill(previous)'
            . ' order by "time" desc limit 10 offset 5 slimit 2 soffset 1 tz(\'Europe/Amsterdam\')',
            $query->toRawSql(),
        );
    }

    #[UnitTest]
    public function testBindingsFollowTheOrderOfThePlaceholdersTheyFill(): void
    {
        $query = $this->builder()
            ->orderByRaw('? desc', [new Expression('time')])
            ->groupByRaw('?', [new Regex('^h')])
            ->where('host', 'web1')
            ->fromRaw('?', [new Regex('^cpu')])
            ->selectRaw('"value" * ? as "scaled"', [2]);

        $this->assertSame('select "value" * ? as "scaled" from ? where "host" = ? group by ? order by ? desc', $query->toSql());
        $this->assertSame('select "value" * 2 as "scaled" from /^cpu/ where "host" = \'web1\' group by /^h/ order by time desc', $query->toRawSql());
    }

    #[UnitTest]
    public function testAnAggregateIsAliasedSoItCanBeReadBack(): void
    {
        $query = $this->table()->select('ignored');
        $query->aggregate = ['function' => 'count', 'columns' => ['value']];

        $this->assertSame('select count("value") as "aggregate" from "cpu"', $query->toSql());
    }

    #[UnitTest]
    public function testAWildcardAggregateIsLeftUnaliased(): void
    {
        $query = $this->table();
        $query->aggregate = ['function' => 'count', 'columns' => ['*']];

        $this->assertSame('select count(*) from "cpu"', $query->toSql());
    }

    #[UnitTest]
    public function testAnAverageIsInfluxqlsMeanAndEveryFunctionIsLowercase(): void
    {
        $query = $this->table();
        $query->aggregate = ['function' => 'avg', 'columns' => ['value']];

        $this->assertSame('select mean("value") as "aggregate" from "cpu"', $query->toSql());

        $query->aggregate = ['function' => 'MAX', 'columns' => ['value']];

        $this->assertSame('select max("value") as "aggregate" from "cpu"', $query->toSql());
    }

    #[UnitTest]
    public function testADistinctAggregateCountsDistinctValues(): void
    {
        $query = $this->table()->distinct();
        $query->aggregate = ['function' => 'count', 'columns' => ['host']];

        $this->assertSame('select count(distinct("host")) as "aggregate" from "cpu"', $query->toSql());

        $query->aggregate = ['function' => 'count', 'columns' => ['*']];

        $this->assertSame('select count(*) from "cpu"', $query->toSql());

        $query = $this->table()->distinct('host');
        $query->aggregate = ['function' => 'count', 'columns' => ['*']];

        $this->assertSame('select count(distinct("host")) as "aggregate" from "cpu"', $query->toSql());
    }

    #[UnitTest]
    public function testAnAggregateTakesExtraArgumentsAsExpressions(): void
    {
        $query = $this->table();
        $query->aggregate = ['function' => 'percentile', 'columns' => ['value', new Expression('95')]];

        $this->assertSame('select percentile("value", 95) as "aggregate" from "cpu"', $query->toSql());
    }

    #[UnitTest]
    public function testCompilingLeavesTheColumnsAsTheyWere(): void
    {
        $query = $this->table();

        $query->toSql();

        $this->assertNull($query->columns);
    }

    #[UnitTest]
    public function testExistsSelectsOnePointOfTheQueryAndLeavesItsLimitAlone(): void
    {
        $query = $this->table()->where('host', 'web1')->limit(10);

        $this->assertSame('select * from "cpu" where "host" = ? limit 1', $query->getGrammar()->compileExists($query));
        $this->assertSame(10, $query->limit);
    }

    #[UnitTest]
    public function testALockAndAnIndexHintCompileToNothing(): void
    {
        $this->assertSame('select * from "cpu"', $this->table()->lockForUpdate()->toSql());
        $this->assertSame('select * from "cpu"', $this->table()->sharedLock()->toSql());
        $this->assertSame('select * from "cpu"', $this->table()->lock('for update skip locked')->toSql());
        $this->assertSame('select * from "cpu"', $this->table()->useIndex('host')->toSql());
    }

    /**
     * InfluxQL cannot express these, so they are refused as the statement compiles, not dropped or sent.
     * Hypervel's grammar refuses the JSON, full-text and vector clauses itself.
     */
    #[UnitTest]
    #[DataProvider('clausesInfluxqlCannotExpress')]
    public function testWhatInfluxqlCannotExpressIsRefusedAsItCompiles(Closure $clause, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs($message);

        $clause($this->table())->toSql();
    }

    /**
     * @return array<string, array{Closure(Builder): Builder, string}>
     */
    public static function clausesInfluxqlCannotExpress(): array
    {
        return [
            'whereNull' => [static fn (Builder $query): Builder => $query->whereNull('host'), 'InfluxQL has no IS NULL; a field or tag cannot be compared with null.'],
            'a null value' => [static fn (Builder $query): Builder => $query->where('host', null), 'InfluxQL has no IS NULL; a field or tag cannot be compared with null.'],
            'whereNotNull' => [static fn (Builder $query): Builder => $query->whereNotNull('host'), 'InfluxQL has no IS NOT NULL; a field or tag cannot be compared with null.'],
            'a null value, negated' => [static fn (Builder $query): Builder => $query->where('host', '!=', null), 'InfluxQL has no IS NOT NULL; a field or tag cannot be compared with null.'],
            'whereNullSafeEquals' => [static fn (Builder $query): Builder => $query->whereNullSafeEquals('host', 'web1'), 'InfluxQL has no null-safe comparison; a field or tag cannot be compared with null.'],
            'the null-safe operator' => [static fn (Builder $query): Builder => $query->where('host', '<=>', 'web1'), 'InfluxQL has no null-safe comparison; a field or tag cannot be compared with null.'],
            'whereLike' => [static fn (Builder $query): Builder => $query->whereLike('host', 'web%'), 'InfluxQL has no LIKE; match a regular expression with =~ instead.'],
            'whereNotLike' => [static fn (Builder $query): Builder => $query->whereNotLike('host', 'web%'), 'InfluxQL has no LIKE; match a regular expression with =~ instead.'],
            'whereDate' => [static fn (Builder $query): Builder => $query->whereDate('time', '2024-01-01'), 'InfluxQL cannot compare the date part of a timestamp; compare time with a date instead.'],
            'whereTime' => [static fn (Builder $query): Builder => $query->whereTime('time', '>', '12:00'), 'InfluxQL cannot compare the time part of a timestamp; compare time with a date instead.'],
            'whereDay' => [static fn (Builder $query): Builder => $query->whereDay('time', 1), 'InfluxQL cannot compare the day part of a timestamp; compare time with a date instead.'],
            'whereMonth' => [static fn (Builder $query): Builder => $query->whereMonth('time', 1), 'InfluxQL cannot compare the month part of a timestamp; compare time with a date instead.'],
            'whereYear' => [static fn (Builder $query): Builder => $query->whereYear('time', 2024), 'InfluxQL cannot compare the year part of a timestamp; compare time with a date instead.'],
            'a sub-select value' => [static fn (Builder $query): Builder => $query->where('value', '>', static fn (Builder $query) => $query->selectRaw('max("value")')->from('mem')), 'InfluxQL cannot compare a column with a sub-select.'],
            'whereExists' => [static fn (Builder $query): Builder => $query->whereExists(static fn (Builder $query) => $query->from('mem')), 'InfluxQL has no EXISTS; query the other measurement on its own instead.'],
            'whereNotExists' => [static fn (Builder $query): Builder => $query->whereNotExists(static fn (Builder $query) => $query->from('mem')), 'InfluxQL has no NOT EXISTS; query the other measurement on its own instead.'],
            'whereIntegerInRaw' => [static fn (Builder $query): Builder => $query->whereIntegerInRaw('value', [1, 2]), 'InfluxQL has no IN; use whereIn(), which compiles to one comparison per value.'],
            'whereIntegerNotInRaw' => [static fn (Builder $query): Builder => $query->whereIntegerNotInRaw('value', [1, 2]), 'InfluxQL has no NOT IN; use whereNotIn(), which compiles to one comparison per value.'],
            'whereBetweenColumns' => [static fn (Builder $query): Builder => $query->whereBetweenColumns('value', ['low', 'high']), 'InfluxQL has no BETWEEN; compare the column with each bound in its own whereColumn() instead.'],
            'whereValueBetween' => [static fn (Builder $query): Builder => $query->whereValueBetween(5, ['low', 'high']), 'InfluxQL has no BETWEEN; compare each column with the value in its own where() instead.'],
            'whereRowValues' => [static fn (Builder $query): Builder => $query->whereRowValues(['host', 'region'], '=', ['web1', 'eu']), 'InfluxQL has no row values; compare each column in its own where() instead.'],
            'whereBinary' => [static fn (Builder $query): Builder => $query->whereBinary('host', 'web1'), 'InfluxQL has no binary comparison.'],
            'a bitwise operator' => [static fn (Builder $query): Builder => $query->where('flags', '&', 4), 'InfluxQL has no bitwise where clause; compare the result of the operation in whereRaw() instead.'],
            'whereNot' => [static fn (Builder $query): Builder => $query->whereNot('host', 'web1'), 'InfluxQL has no NOT; negate the comparison instead, such as != for = or !~ for =~.'],
            'orWhereNot' => [static fn (Builder $query): Builder => $query->where('value', '>', 1)->orWhereNot(static fn (Builder $query) => $query->where('host', 'web1')), 'InfluxQL has no NOT; negate the comparison instead, such as != for = or !~ for =~.'],
            'whereNot inside a group' => [static fn (Builder $query): Builder => $query->where(static fn (Builder $query) => $query->whereNot('host', 'web1')), 'InfluxQL has no NOT; negate the comparison instead, such as != for = or !~ for =~.'],
            'a join' => [static fn (Builder $query): Builder => $query->join('mem', 'cpu.host', '=', 'mem.host'), 'InfluxQL has no joins; query each measurement on its own.'],
            'a having' => [static fn (Builder $query): Builder => $query->groupBy('host')->having('value', '>', 1), 'InfluxQL has no HAVING; filter the aggregate in an outer query that selects from this one instead.'],
            'a union' => [static fn (Builder $query): Builder => $query->union(static fn (Builder $query) => $query->from('mem')), 'InfluxQL has no UNION; run each query on its own.'],
            'an aggregate over a union' => [static function (Builder $query): Builder {
                $query->union(static fn (Builder $query) => $query->from('mem'))->aggregate = ['function' => 'count', 'columns' => ['*']];

                return $query;
            }, 'InfluxQL has no UNION; run each query on its own.'],
            'a group limit' => [static fn (Builder $query): Builder => $query->groupLimit(2, 'host'), 'InfluxQL has no window functions to limit each group; a limit on a query grouped by tags applies to each series instead.'],
            'a random order' => [static fn (Builder $query): Builder => $query->inRandomOrder(), 'InfluxQL sorts by time only; it cannot order randomly.'],
            'whereJsonContains' => [static fn (Builder $query): Builder => $query->whereJsonContains('tags', 'web'), 'This database engine does not support JSON contains operations.'],
            'whereJsonOverlaps' => [static fn (Builder $query): Builder => $query->whereJsonOverlaps('tags', ['web']), 'This database engine does not support JSON overlaps operations.'],
            'whereJsonContainsKey' => [static fn (Builder $query): Builder => $query->whereJsonContainsKey('tags->web'), 'This database engine does not support JSON contains key operations.'],
            'whereJsonLength' => [static fn (Builder $query): Builder => $query->whereJsonLength('tags', 2), 'This database engine does not support JSON length operations.'],
            'a JSON path compared with a boolean' => [static fn (Builder $query): Builder => $query->where('tags->web', true), 'This database engine does not support JSON operations.'],
            'whereFullText' => [static fn (Builder $query): Builder => $query->whereFullText('message', 'error'), 'This database engine does not support fulltext search operations.'],
            'whereVectorDistanceLessThan' => [static fn (Builder $query): Builder => $query->whereVectorDistanceLessThan('embedding', [0.1, 0.2], 0.5), 'Vector distance queries are only supported by Postgres and MariaDB.'],
        ];
    }

    /**
     * Points are written through InfluxDB::writeApi(), never through a query.
     */
    #[UnitTest]
    #[DataProvider('writes')]
    public function testEveryWriteIsRefusedInFavourOfTheWriteApi(Closure $write): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('InfluxQL has no INSERT, UPDATE, UPSERT or TRUNCATE; write points through InfluxDB::writeApi() instead.');

        $write($this->table());
    }

    /**
     * @return array<string, array{Closure(Builder): mixed}>
     */
    public static function writes(): array
    {
        return [
            'insert' => [static fn (Builder $query): bool => $query->insert(['value' => 1])],
            'insertOrIgnore' => [static fn (Builder $query): int => $query->insertOrIgnore(['value' => 1])],
            'insertOrIgnoreReturning' => [static fn (Builder $query): mixed => $query->insertOrIgnoreReturning(['value' => 1])],
            'insertGetId' => [static fn (Builder $query): int|string => $query->insertGetId(['value' => 1])],
            'insertUsing' => [static fn (Builder $query): int => $query->insertUsing(['value'], static fn (Builder $query) => $query->from('mem'))],
            'insertOrIgnoreUsing' => [static fn (Builder $query): int => $query->insertOrIgnoreUsing(['value'], static fn (Builder $query) => $query->from('mem'))],
            'update' => [static fn (Builder $query): int => $query->update(['value' => 1])],
            'upsert' => [static fn (Builder $query): int => $query->upsert([['time' => 1, 'value' => 1]], 'time')],
            'truncate' => [static fn (Builder $query) => $query->truncate()],
        ];
    }

    #[UnitTest]
    public function testDeleteTakesTheMeasurementAndTheWhereClauseOnly(): void
    {
        $grammar = $this->grammar();

        $this->assertSame('delete from "cpu"', $grammar->compileDelete($this->table()));
        $this->assertSame(
            'delete from "cpu" where "host" = ? and "time" < ?',
            $grammar->compileDelete($this->table()->select('value')->where('host', 'web1')->where('time', '<', new DateTimeImmutable)->orderByDesc('time')),
        );
        $this->assertSame('delete from ?', $grammar->compileDelete($this->builder()->fromRaw('?', [new Regex('^cpu')])));
        $this->assertSame('delete from "disk.io"', $grammar->compileDelete($this->builder()->from(new Expression('"disk.io"'))));
    }

    #[UnitTest]
    public function testDeleteNeedsAMeasurement(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('An InfluxQL DELETE needs a measurement; call from() first.');

        $this->grammar()->compileDelete($this->builder()->where('host', 'web1'));
    }

    /**
     * InfluxDB's parser refuses a database or retention policy in a DELETE.
     */
    #[UnitTest]
    #[DataProvider('qualifiedMeasurements')]
    public function testDeleteRefusesAQualifiedMeasurement(string $measurement): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs("An InfluxQL DELETE cannot name a database or retention policy, as [{$measurement}] does; pass the measurement alone, or as an Expression if the dot is part of its name.");

        $this->grammar()->compileDelete($this->table($measurement)->where('host', 'web1'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function qualifiedMeasurements(): array
    {
        return [
            'a retention policy' => ['autogen.cpu'],
            'a database and a retention policy' => ['telegraf.autogen.cpu'],
            'a database on its default policy' => ['telegraf..cpu'],
        ];
    }

    #[UnitTest]
    public function testDeleteRefusesAnAliasedMeasurement(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('InfluxQL cannot alias a measurement, as [cpu as c] does; name the measurement alone.');

        $this->grammar()->compileDelete($this->builder()->from('cpu', 'c'));
    }

    /**
     * InfluxQL's DELETE has no JOIN, LIMIT or OFFSET, and dropping one would delete more points than the query selects.
     */
    #[UnitTest]
    #[DataProvider('narrowedDeletes')]
    public function testDeleteRefusesAJoinALimitOrAnOffset(Closure $narrow): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('An InfluxQL DELETE takes no join, limit or offset; narrow it with where() instead.');

        $this->grammar()->compileDelete($narrow($this->table()->where('host', 'web1')));
    }

    /**
     * @return array<string, array{Closure(Builder): Builder}>
     */
    public static function narrowedDeletes(): array
    {
        return [
            'a join' => [static fn (Builder $query): Builder => $query->join('mem', 'cpu.host', '=', 'mem.host')],
            'a limit' => [static fn (Builder $query): Builder => $query->limit(5)],
            'a limit of zero' => [static fn (Builder $query): Builder => $query->limit(0)],
            'an offset' => [static fn (Builder $query): Builder => $query->offset(5)],
        ];
    }

    #[UnitTest]
    public function testStringLiteralsEscapeQuotesBackslashesAndNewlinesWithABackslash(): void
    {
        $grammar = $this->grammar();

        $this->assertSame("'it\\'s'", $grammar->quoteString("it's"));
        $this->assertSame("'a\\\\b'", $grammar->quoteString('a\b'));
        $this->assertSame("'a\\nb'", $grammar->quoteString("a\nb"));
        $this->assertSame("'a', 'b\\'c'", $grammar->quoteString(['a', "b'c"]));
    }

    /**
     * The Dialect writes every value, not SQL escaping: a boolean is not an integer, a quote is not doubled,
     * a date is in UTC, and a regular expression has a literal of its own.
     */
    #[UnitTest]
    #[DataProvider('literals')]
    public function testEveryValueIsEmbeddedAsItsInfluxqlLiteral(mixed $value, string $literal): void
    {
        $this->assertSame($literal, $this->grammar()->substituteBindingsIntoRawSql('?', [$value]));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function literals(): array
    {
        return [
            'string with a quote' => ["o'clock", "'o\\'clock'"],
            'integer' => [42, '42'],
            'true' => [true, 'true'],
            'false' => [false, 'false'],
            'whole float keeps its point' => [1.0, '1.0'],
            'float below 1e-4' => [1.5E-7, '0.00000015'],
            'date' => [new DateTimeImmutable('2024-06-01 12:34:56.789', new DateTimeZone('America/New_York')), "'2024-06-01T16:34:56.789000Z'"],
            'regex' => [new Regex('^web\d+$'), '/^web\d+$/'],
            'expression' => [new Expression('now() - 1h'), 'now() - 1h'],
            'numeric expression' => [new Expression(5), '5'],
        ];
    }

    #[UnitTest]
    #[DataProvider('valuesWithoutALiteral')]
    public function testAValueWithoutALiteralIsRefused(mixed $value, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs($message);

        $this->grammar()->substituteBindingsIntoRawSql('?', [$value]);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function valuesWithoutALiteral(): array
    {
        return [
            'null' => [null, 'InfluxQL has no null literal; a field or tag cannot be compared with null.'],
            'INF' => [INF, 'InfluxQL has no literal for NAN or INF.'],
            'array' => [['a'], 'An array cannot be embedded in InfluxQL; use whereIn() or whereBetween().'],
            'the standalone builder\'s expression' => [new InfluxQLExpression('now()'), sprintf('A value of type %s cannot be embedded in InfluxQL.', InfluxQLExpression::class)],
        ];
    }

    #[UnitTest]
    public function testSubstitutionSkipsPlaceholdersInsideLiteralsAndIdentifiers(): void
    {
        $sql = <<<'SQL'
            select "a?b" from "cpu" where "host" = 'it\'s ?' and "tag\"?" = ? and "value" > ?
            SQL;

        $this->assertSame(
            <<<'SQL'
                select "a?b" from "cpu" where "host" = 'it\'s ?' and "tag\"?" = 'x' and "value" > 5
                SQL,
            $this->grammar()->substituteBindingsIntoRawSql($sql, ['x', 5]),
        );
    }

    #[UnitTest]
    public function testSubstitutionLeavesAPlaceholderWithNoBindingAndReindexesTheBindings(): void
    {
        $this->assertSame('select * from "cpu" where "a" = 1 and "b" = ?', $this->grammar()->substituteBindingsIntoRawSql('select * from "cpu" where "a" = ? and "b" = ?', [1]));
        $this->assertSame('1 2', $this->grammar()->substituteBindingsIntoRawSql('? ?', ['first' => 1, 'second' => 2]));
    }

    #[UnitTest]
    public function testTheHelpersWrapQuoteAndPlaceholdAsTheStandaloneGrammarDoes(): void
    {
        $grammar = $this->grammar();

        $this->assertSame(['"a"', 'b'], $grammar->wrapArray(['a', new Expression('b')]));
        $this->assertSame('"a", "b"', $grammar->columnize(['a', 'b']));
        $this->assertSame('?, now()', $grammar->parameterize(['a', new Expression('now()')]));
        $this->assertSame('?', $grammar->parameter(new Regex('x')));
        $this->assertSame('"value"::field as "v"', $grammar->wrap('value::field as v'));
        $this->assertSame('"a.b"', $grammar->wrapTable(new Expression('"a.b"')));
        $this->assertSame(Dialect::OPERATORS, $grammar->getOperators());
        $this->assertSame('Y-m-d\TH:i:s.u\Z', $grammar->getDateFormat());
    }

    #[UnitTest]
    public function testV2CompilesEveryClauseButIntoAsV1Does(): void
    {
        $this->assertSame(
            'select mean("usage_user") as "mean" from "telegraf"."autogen"."cpu" where "host" =~ /^web/ and "time" >= \'2024-01-01T00:00:00.000000Z\''
            . ' group by time(1h), "region" fill(previous) order by "time" desc limit 10 offset 5 slimit 2 soffset 1 tz(\'Europe/Amsterdam\')',
            $this->everyClause(Version::V2)->toRawSql(),
        );
        $this->assertSame($this->everyClause(Version::V1)->toRawSql(), $this->everyClause(Version::V2)->toRawSql());
    }

    #[UnitTest]
    public function testV2RefusesSelectInto(): void
    {
        $query = $this->table(version: Version::V2)->selectRaw('mean("value")')->groupByRaw('time(1h)');
        $query->into = 'cpu_hourly';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('InfluxDB 2.x does not support SELECT ... INTO; downsample with a task instead.');

        $query->toSql();
    }

    /**
     * InfluxDB 2.x runs a DELETE on the default retention policy, the one a connection addressing no policy reads.
     */
    #[UnitTest]
    public function testV2CompilesADeleteOnAConnectionThatAddressesNoRetentionPolicy(): void
    {
        $grammar = $this->grammar(Version::V2);

        $this->assertSame('delete from "cpu" where "host" = ?', $grammar->compileDelete($this->table()->where('host', 'web1')));
        $this->assertSame('delete from ? where "host" = ?', $grammar->compileDelete($this->builder()->fromRaw('?', [new Regex('^cpu')])->where('host', 'web1')));
    }

    #[UnitTest]
    public function testV2RefusesADeleteOnAConnectionThatAddressesARetentionPolicy(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs("InfluxDB 2.x deletes only from the database's default retention policy, not from the connection's [autogen]; delete through the /api/v2/delete API instead.");

        $this->grammar(Version::V2, 'autogen')->compileDelete($this->table()->where('host', 'web1'));
    }

    #[UnitTest]
    public function testV1CompilesADeleteWhateverPolicyTheConnectionAddresses(): void
    {
        $this->assertSame('delete from "cpu" where "host" = ?', $this->grammar(Version::V1, 'weekly')->compileDelete($this->table()->where('host', 'web1')));
    }

    #[UnitTest]
    public function testV3CompilesEveryClauseButIntoSlimitAndSoffsetAsV1Does(): void
    {
        $query = fn (Version $version): Builder => tap($this->everyClause($version), function (Builder $query): void {
            $query->slimit = null;
            $query->soffset = null;
        });

        $this->assertSame(
            'select mean("usage_user") as "mean" from "telegraf"."autogen"."cpu" where "host" =~ /^web/ and "time" >= \'2024-01-01T00:00:00.000000Z\''
            . ' group by time(1h), "region" fill(previous) order by "time" desc limit 10 offset 5 tz(\'Europe/Amsterdam\')',
            $query(Version::V3)->toRawSql(),
        );
        $this->assertSame($query(Version::V1)->toRawSql(), $query(Version::V3)->toRawSql());
    }

    #[UnitTest]
    public function testV3RefusesSelectInto(): void
    {
        $query = $this->table(version: Version::V3)->selectRaw('mean("value")')->groupByRaw('time(1h)');
        $query->into = 'cpu_hourly';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('InfluxDB 3 does not support SELECT ... INTO; downsample with the processing engine instead.');

        $query->toSql();
    }

    /**
     * InfluxDB 3 refuses SLIMIT and SOFFSET whatever their value, so the no-op 0 compiles to nothing.
     */
    #[UnitTest]
    public function testV3CompilesASlimitOrSoffsetOfZeroToNothing(): void
    {
        $query = function (Version $version): Builder {
            $query = $this->table(version: $version)->groupBy('*');
            $query->slimit = 0;
            $query->soffset = 0;

            return $query;
        };

        $this->assertSame('select * from "cpu" group by *', $query(Version::V3)->toSql());
        $this->assertSame('select * from "cpu" group by * slimit 0 soffset 0', $query(Version::V1)->toSql());
    }

    #[UnitTest]
    #[DataProvider('seriesClausesInfluxdb3Refuses')]
    public function testV3RefusesAnySlimitOrSoffsetButZero(Closure $clause, string $message): void
    {
        $query = $clause($this->table(version: Version::V3)->groupBy('*'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs($message);

        $query->toSql();
    }

    /**
     * @return array<string, array{Closure(Builder): Builder, string}>
     */
    public static function seriesClausesInfluxdb3Refuses(): array
    {
        return [
            'slimit' => [static fn (Builder $query): Builder => tap($query, static function (Builder $query): void {
                $query->slimit = 2;
            }), 'InfluxDB 3 does not support SLIMIT.'],
            'soffset' => [static fn (Builder $query): Builder => tap($query, static function (Builder $query): void {
                $query->soffset = 1;
            }), 'InfluxDB 3 does not support SOFFSET.'],
        ];
    }

    #[UnitTest]
    #[DataProvider('deletesInfluxdb3Refuses')]
    public function testV3RefusesEveryDelete(Closure $delete): void
    {
        $query = $delete($this->builder());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('InfluxDB 3 does not support DELETE; delete the table or the database instead.');

        $this->grammar(Version::V3)->compileDelete($query);
    }

    /**
     * @return array<string, array{Closure(Builder): Builder}>
     */
    public static function deletesInfluxdb3Refuses(): array
    {
        return [
            'a whole measurement' => [static fn (Builder $query): Builder => $query->from('cpu')],
            'a where clause' => [static fn (Builder $query): Builder => $query->from('cpu')->where('host', 'web1')],
            'a regex measurement' => [static fn (Builder $query): Builder => $query->fromRaw('?', [new Regex('^cpu')])],
            'no measurement' => [static fn (Builder $query): Builder => $query],
        ];
    }

    /**
     * A query using every clause, on a connection to the given version.
     */
    private function everyClause(Version $version): Builder
    {
        $query = $this->builder($version)
            ->selectRaw('mean("usage_user") as "mean"')
            ->from('telegraf.autogen.cpu')
            ->where('host', '=~', new Regex('^web'))
            ->where('time', '>=', new DateTimeImmutable('2024-01-01T00:00:00Z'))
            ->groupByRaw('time(1h)')
            ->groupBy('region')
            ->orderByDesc('time')
            ->limit(10)
            ->offset(5);
        $query->fill = 'previous';
        $query->slimit = 2;
        $query->soffset = 1;
        $query->timezone = 'Europe/Amsterdam';

        return $query;
    }

    /**
     * A query on a measurement, on a connection to the given version.
     */
    private function table(string $measurement = 'cpu', Version $version = Version::V1, ?string $retentionPolicy = null): Builder
    {
        return $this->builder($version, $retentionPolicy)->from($measurement);
    }

    /**
     * The grammar of a connection to the given version, addressing the given retention policy.
     */
    private function grammar(Version $version = Version::V1, ?string $retentionPolicy = null): Grammar
    {
        return $this->builder($version, $retentionPolicy)->getGrammar();
    }

    /**
     * A query on no measurement yet, on a connection to the given version.
     *
     * The connection hands values over unprepared, so the Dialect escapes dates and booleans too, and names its
     * database, which Hypervel's builder compares when it embeds a sub-query.
     */
    private function builder(Version $version = Version::V1, ?string $retentionPolicy = null): Builder
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('prepareBindings')->andReturnUsing(static fn (array $bindings): array => $bindings);
        $connection->shouldReceive('getDatabaseName')->andReturn('telegraf');

        return new class($connection, new Grammar($connection, $version, $retentionPolicy), new Processor) extends Builder {
            public ExpressionContract|string|null $into = null;

            public string|int|float|null $fill = null;

            public ?int $slimit = null;

            public ?int $soffset = null;

            public ?string $timezone = null;
        };
    }
}
