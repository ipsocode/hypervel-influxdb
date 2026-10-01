<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature\InfluxQL;

use Closure;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Hypervel\Foundation\Testing\Attributes\UnitTest;
use InvalidArgumentException;
use Ipsocode\InfluxDB\InfluxQL\Builder;
use Ipsocode\InfluxDB\InfluxQL\Expression;
use Ipsocode\InfluxDB\InfluxQL\Grammars\V1Grammar;
use Ipsocode\InfluxDB\InfluxQL\Grammars\V2Grammar;
use Ipsocode\InfluxDB\InfluxQL\Grammars\V3Grammar;
use Ipsocode\InfluxDB\InfluxQL\Regex;
use Ipsocode\InfluxDB\Tests\Concerns\MocksInfluxQL;
use Ipsocode\InfluxDB\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use SortDirection;
use stdClass;
use Stringable;

/**
 * Every clause the grammar compiles and the literals it embeds, on a 1.x connection since they compile the
 * same for every version, then what each version's grammar refuses. BuilderTest runs the statements.
 */
class GrammarTest extends TestCase
{
    use MocksInfluxQL;

    #[UnitTest]
    public function testAQueryWithNoColumnsSelectsEverything(): void
    {
        $this->assertSame('SELECT * FROM "cpu"', $this->table()->toSql());
    }

    #[UnitTest]
    public function testColumnsAreDoubleQuotedAndTheWildcardIsNot(): void
    {
        $this->assertSame('SELECT "usage_user", "host" FROM "cpu"', $this->table()->select('usage_user', 'host')->toSql());
        $this->assertSame('SELECT "usage_user", "host" FROM "cpu"', $this->table()->select(['usage_user', 'host'])->toSql());
        $this->assertSame('SELECT *, "host" FROM "cpu"', $this->table()->select('*', 'host')->toSql());
    }

    #[UnitTest]
    public function testAColumnIsQuotedWholeRatherThanSplitOnDots(): void
    {
        $this->assertSame('SELECT "disk.used" FROM "cpu"', $this->table()->select('disk.used')->toSql());
    }

    #[UnitTest]
    public function testAnAliasIsQuotedOnBothSidesOfAs(): void
    {
        $this->assertSame(
            'SELECT "usage_user" AS "user", "usage_system" AS "system" FROM "cpu"',
            $this->table()->select('usage_user as user', 'usage_system AS system')->toSql(),
        );
    }

    #[UnitTest]
    public function testATypeHintStaysOutsideTheQuotes(): void
    {
        $this->assertSame(
            'SELECT "host"::tag, "value"::field, "load"::float, "count"::integer FROM "cpu"',
            $this->table()->select('host::tag', 'value::field', 'load::FLOAT', 'count::integer')->toSql(),
        );
        $this->assertSame('SELECT "value"::field AS "v" FROM "cpu"', $this->table()->select('value::field as v')->toSql());
    }

    #[UnitTest]
    public function testIdentifiersEscapeQuotesBackslashesAndNewlinesWithABackslash(): void
    {
        $this->assertSame(
            'SELECT "a\"b", "c\\\d", "e\nf" FROM "cpu"',
            $this->table()->select('a"b', 'c\d', "e\nf")->toSql(),
        );
    }

    #[UnitTest]
    public function testARawSelectIsEmbeddedAsWrittenWithItsBindings(): void
    {
        $query = $this->table()->selectRaw('MEAN("value") * ? AS "scaled"', [2]);

        $this->assertSame('SELECT MEAN("value") * ? AS "scaled" FROM "cpu"', $query->toSql());
        $this->assertSame('SELECT MEAN("value") * 2 AS "scaled" FROM "cpu"', $query->toRawSql());
    }

    #[UnitTest]
    public function testAddSelectAppendsColumnsOnce(): void
    {
        $this->assertSame('SELECT "a" FROM "cpu"', $this->table()->addSelect('a')->toSql());
        $this->assertSame('SELECT "a", "b" FROM "cpu"', $this->table()->select('a')->addSelect('b', 'a')->toSql());
        $this->assertSame('SELECT "a", "b", "c" FROM "cpu"', $this->table()->select('a')->addSelect(['b', 'c'])->toSql());
    }

    #[UnitTest]
    public function testDistinctWrapsEachColumnInTheDistinctFunctionAndKeepsTheAliasOutside(): void
    {
        $this->assertSame('SELECT DISTINCT("host") FROM "cpu"', $this->table()->select('host')->distinct()->toSql());
        $this->assertSame('SELECT DISTINCT("value") AS "v" FROM "cpu"', $this->table()->select('value as v')->distinct()->toSql());
        $this->assertSame('SELECT DISTINCT(*) FROM "cpu"', $this->table()->distinct()->toSql());
        $this->assertSame('SELECT DISTINCT("value"::field) FROM "cpu"', $this->table()->select(new Expression('"value"::field'))->distinct()->toSql());
    }

    #[UnitTest]
    public function testADottedMeasurementIsQualifiedSegmentBySegment(): void
    {
        $this->assertSame('SELECT * FROM "telegraf"."autogen"."cpu"', $this->table('telegraf.autogen.cpu')->toSql());
        $this->assertSame('SELECT * FROM "autogen"."cpu"', $this->table('autogen.cpu')->toSql());
        $this->assertSame('SELECT * FROM "telegraf".."cpu"', $this->table('telegraf..cpu')->toSql());
    }

    #[UnitTest]
    public function testAMeasurementWhoseNameHasADotIsAnExpression(): void
    {
        $this->assertSame('SELECT * FROM "disk.io"', $this->table()->from(new Expression('"disk.io"'))->toSql());
    }

    #[UnitTest]
    public function testMeasurementIdentifiersAreEscaped(): void
    {
        $this->assertSame('SELECT * FROM "c\"p\\\u"', $this->table('c"p\u')->toSql());
    }

    #[UnitTest]
    public function testARegexMeasurementIsABindingRenderedAsARegexLiteral(): void
    {
        $query = $this->table()->from(new Regex('^(cpu|mem)$'));

        $this->assertSame('SELECT * FROM ?', $query->toSql());
        $this->assertSame('SELECT * FROM /^(cpu|mem)$/', $query->toRawSql());
    }

    #[UnitTest]
    public function testReplacingARegexMeasurementDropsItsBinding(): void
    {
        $query = $this->table()->from(new Regex('^cpu'))->from('mem');

        $this->assertSame([], $query->getBindings());
        $this->assertSame('SELECT * FROM "mem"', $query->toRawSql());
    }

    #[UnitTest]
    public function testARawFromIsEmbeddedAsWrittenWithItsBindings(): void
    {
        $this->assertSame('SELECT * FROM "cpu", "mem"', $this->builder()->fromRaw('"cpu", "mem"')->toRawSql());
        $this->assertSame('SELECT * FROM /^cpu/', $this->builder()->fromRaw('?', new Regex('^cpu'))->toRawSql());
        $this->assertSame('SELECT * FROM "cpu"', $this->builder()->fromRaw(new Expression('"cpu"'))->toRawSql());
    }

    #[UnitTest]
    public function testAClosureFromIsASubqueryWithItsBindings(): void
    {
        $query = $this->builder()->select('mean')->from(function (Builder $query): void {
            $query->selectRaw('MEAN("value") AS "mean"')->from('cpu')->where('host', 'web1')->groupByTime('10m');
        });

        $this->assertSame('SELECT "mean" FROM (SELECT MEAN("value") AS "mean" FROM "cpu" WHERE "host" = ? GROUP BY time(10m))', $query->toSql());
        $this->assertSame(['web1'], $query->getBindings());
        $this->assertSame('SELECT "mean" FROM (SELECT MEAN("value") AS "mean" FROM "cpu" WHERE "host" = \'web1\' GROUP BY time(10m))', $query->toRawSql());
    }

    #[UnitTest]
    public function testABuilderFromIsASubquery(): void
    {
        $inner = $this->table()->selectRaw('MAX("value") AS "max"')->where('host', 'web1')->groupByTime('1h');

        $this->assertSame(
            'SELECT MEAN("max") FROM (SELECT MAX("value") AS "max" FROM "cpu" WHERE "host" = \'web1\' GROUP BY time(1h))',
            $this->builder()->selectRaw('MEAN("max")')->fromSub($inner)->toRawSql(),
        );
    }

    #[UnitTest]
    public function testIntoComesBetweenTheColumnsAndFrom(): void
    {
        $this->assertSame(
            'SELECT MEAN("value") INTO "telegraf"."autogen"."cpu_hourly" FROM "cpu" GROUP BY time(1h)',
            $this->table()->selectRaw('MEAN("value")')->into('telegraf.autogen.cpu_hourly')->groupByTime('1h')->toSql(),
        );
    }

    #[UnitTest]
    public function testAnIntoExpressionCarriesAMeasurementBackreference(): void
    {
        $this->assertSame(
            'SELECT MEAN(*) INTO "downsampled"."autogen".:MEASUREMENT FROM /.*/ GROUP BY time(1h)',
            $this->builder()->selectRaw('MEAN(*)')->into(new Expression('"downsampled"."autogen".:MEASUREMENT'))->from(new Regex('.*'))->groupByTime('1h')->toRawSql(),
        );
    }

    #[UnitTest]
    public function testABasicWhereComparesAColumnWithABinding(): void
    {
        $query = $this->table()->where('host', 'web1');

        $this->assertSame('SELECT * FROM "cpu" WHERE "host" = ?', $query->toSql());
        $this->assertSame(['web1'], $query->getBindings());
        $this->assertSame('SELECT * FROM "cpu" WHERE "host" = \'web1\'', $query->toRawSql());
    }

    #[UnitTest]
    #[DataProvider('operators')]
    public function testEveryComparisonOperatorCompilesAsWritten(string $operator): void
    {
        $this->assertSame(
            "SELECT * FROM \"cpu\" WHERE \"value\" {$operator} 5",
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
    public function testWheresChainWithAndAndOr(): void
    {
        $this->assertSame(
            'SELECT * FROM "cpu" WHERE "host" = \'web1\' AND "value" > 5 OR "region" = \'eu\'',
            $this->table()->where('host', 'web1')->where('value', '>', 5)->orWhere('region', 'eu')->toRawSql(),
        );
        $this->assertSame(
            'SELECT * FROM "cpu" WHERE "value" > 5 OR "value" < 1',
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

        $this->assertSame('SELECT * FROM "cpu" WHERE ("host" = ? OR "host" = ?) AND "value" > ? OR ("region" = ?)', $query->toSql());
        $this->assertSame(['web1', 'web2', 5, 'eu'], $query->getBindings());
    }

    #[UnitTest]
    public function testAnEmptyNestedGroupIsLeftOut(): void
    {
        $this->assertSame('SELECT * FROM "cpu"', $this->table()->where(function (Builder $query): void {})->toSql());
    }

    #[UnitTest]
    public function testAnArrayOfWheresIsANestedGroupOfEqualities(): void
    {
        $this->assertSame(
            'SELECT * FROM "cpu" WHERE ("host" = \'web1\' AND "region" = \'eu\')',
            $this->table()->where(['host' => 'web1', 'region' => 'eu'])->toRawSql(),
        );
    }

    #[UnitTest]
    public function testAListOfWheresPassesEachEntryAsArguments(): void
    {
        $this->assertSame(
            'SELECT * FROM "cpu" WHERE ("value" > 5 AND "host" = \'web1\')',
            $this->table()->where([['value', '>', 5], ['host', 'web1']])->toRawSql(),
        );
    }

    #[UnitTest]
    public function testAnOrArrayOfWheresOrsItsEntriesToo(): void
    {
        $this->assertSame(
            'SELECT * FROM "cpu" WHERE "value" > 5 OR ("host" = \'web1\' OR "host" = \'web2\')',
            $this->table()->where('value', '>', 5)->orWhere([['host', 'web1'], ['host', '=', 'web2']])->toRawSql(),
        );
    }

    #[UnitTest]
    public function testAnExpressionValueIsEmbeddedAsWrittenWithoutABinding(): void
    {
        $query = $this->table()->where('time', '>', new Expression('now() - 1h'));

        $this->assertSame('SELECT * FROM "cpu" WHERE "time" > now() - 1h', $query->toSql());
        $this->assertSame([], $query->getBindings());
    }

    #[UnitTest]
    public function testAnExpressionColumnIsEmbeddedAsWritten(): void
    {
        $this->assertSame(
            'SELECT * FROM "cpu" WHERE "usage_user" + "usage_system" > 90',
            $this->table()->where(new Expression('"usage_user" + "usage_system"'), '>', 90)->toRawSql(),
        );
    }

    #[UnitTest]
    public function testWhereColumnComparesTwoColumns(): void
    {
        $this->assertSame('SELECT * FROM "cpu" WHERE "usage_user" > "usage_system"', $this->table()->whereColumn('usage_user', '>', 'usage_system')->toSql());
        $this->assertSame('SELECT * FROM "cpu" WHERE "a" = "b"', $this->table()->whereColumn('a', 'b')->toSql());
        $this->assertSame('SELECT * FROM "cpu" WHERE "a" = "b" OR "c" < "d"', $this->table()->whereColumn('a', 'b')->orWhereColumn('c', '<', 'd')->toSql());
    }

    #[UnitTest]
    public function testAnArrayOfColumnComparisonsIsANestedGroup(): void
    {
        $this->assertSame(
            'SELECT * FROM "cpu" WHERE ("a" = "b" AND "c" > "d")',
            $this->table()->whereColumn([['a', 'b'], ['c', '>', 'd']])->toSql(),
        );
        $this->assertSame('SELECT * FROM "cpu" WHERE ("a" = "b")', $this->table()->whereColumn(['a' => 'b'])->toSql());
    }

    #[UnitTest]
    public function testARawWhereIsEmbeddedAsWrittenWithItsBindings(): void
    {
        $query = $this->table()->whereRaw('"value" > ? AND "value" < ?', [1, 10])->orWhereRaw('"host" = ?', 'web1');

        $this->assertSame('SELECT * FROM "cpu" WHERE "value" > ? AND "value" < ? OR "host" = ?', $query->toSql());
        $this->assertSame('SELECT * FROM "cpu" WHERE "value" > 1 AND "value" < 10 OR "host" = \'web1\'', $query->toRawSql());
        $this->assertSame('SELECT * FROM "cpu" WHERE time > now() - 1h', $this->table()->whereRaw(new Expression('time > now() - 1h'))->toSql());
    }

    #[UnitTest]
    public function testARawWhereTakesABareRegexOrDateBinding(): void
    {
        $this->assertSame(
            'SELECT * FROM "cpu" WHERE "host" =~ /^web/',
            $this->table()->whereRaw('"host" =~ ?', new Regex('^web'))->toRawSql(),
        );
        $this->assertSame(
            'SELECT * FROM "cpu" WHERE time > \'2024-01-01T00:00:00.000000Z\'',
            $this->table()->whereRaw('time > ?', new DateTimeImmutable('2024-01-01T00:00:00Z'))->toRawSql(),
        );
    }

    #[UnitTest]
    public function testWhereInIsTheOrOfOneEqualityPerValue(): void
    {
        $query = $this->table()->whereIn('host', ['web1', 'web2']);

        $this->assertSame('SELECT * FROM "cpu" WHERE ("host" = ? OR "host" = ?)', $query->toSql());
        $this->assertSame('SELECT * FROM "cpu" WHERE ("host" = \'web1\' OR "host" = \'web2\')', $query->toRawSql());
    }

    #[UnitTest]
    public function testWhereNotInIsTheAndOfOneInequalityPerValue(): void
    {
        $this->assertSame(
            'SELECT * FROM "cpu" WHERE ("host" != \'web1\' AND "host" != \'web2\')',
            $this->table()->whereNotIn('host', ['web1', 'web2'])->toRawSql(),
        );
    }

    #[UnitTest]
    public function testAnEmptyWhereInMatchesNothingAndAnEmptyWhereNotInEverything(): void
    {
        $this->assertSame('SELECT * FROM "cpu" WHERE 0 = 1', $this->table()->whereIn('host', [])->toSql());
        $this->assertSame('SELECT * FROM "cpu" WHERE 1 = 1', $this->table()->whereNotIn('host', [])->toSql());
    }

    #[UnitTest]
    public function testOrWhereInAndOrWhereNotInJoinWithOr(): void
    {
        $this->assertSame(
            'SELECT * FROM "cpu" WHERE "value" > 5 OR ("host" = \'web1\') OR ("region" != \'eu\')',
            $this->table()->where('value', '>', 5)->orWhereIn('host', ['web1'])->orWhereNotIn('region', ['eu'])->toRawSql(),
        );
    }

    #[UnitTest]
    public function testAnExpressionInAWhereInListIsEmbeddedWithoutABinding(): void
    {
        $query = $this->table()->whereIn('value', [1, new Expression('2 + 1')]);

        $this->assertSame('SELECT * FROM "cpu" WHERE ("value" = ? OR "value" = 2 + 1)', $query->toSql());
        $this->assertSame([1], $query->getBindings());
    }

    #[UnitTest]
    public function testWhereBetweenIsTwoInclusiveComparisons(): void
    {
        $query = $this->table()->whereBetween('value', [1, 10]);

        $this->assertSame('SELECT * FROM "cpu" WHERE ("value" >= ? AND "value" <= ?)', $query->toSql());
        $this->assertSame('SELECT * FROM "cpu" WHERE ("value" >= 1 AND "value" <= 10)', $query->toRawSql());
    }

    #[UnitTest]
    public function testWhereNotBetweenIsTwoExclusiveComparisons(): void
    {
        $this->assertSame(
            'SELECT * FROM "cpu" WHERE ("value" < 1 OR "value" > 10)',
            $this->table()->whereNotBetween('value', [1, 10])->toRawSql(),
        );
    }

    #[UnitTest]
    public function testOrWhereBetweenAndOrWhereNotBetweenJoinWithOr(): void
    {
        $this->assertSame(
            'SELECT * FROM "cpu" WHERE "host" = \'web1\' OR ("value" >= 1 AND "value" <= 2) OR ("value" < 5 OR "value" > 6)',
            $this->table()->where('host', 'web1')->orWhereBetween('value', [1, 2])->orWhereNotBetween('value', [5, 6])->toRawSql(),
        );
    }

    #[UnitTest]
    public function testATimeWindowWithDatesIsEmbeddedInUtc(): void
    {
        $this->assertSame(
            'SELECT * FROM "cpu" WHERE ("time" >= \'2024-01-01T00:00:00.000000Z\' AND "time" <= \'2024-01-01T23:59:59.500000Z\')',
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

        $this->assertSame('SELECT * FROM "cpu" WHERE ("time" >= now() - 1h AND "time" <= now())', $query->toSql());
        $this->assertSame([], $query->getBindings());
    }

    #[UnitTest]
    public function testARegexWhereTakesTheRegexOperators(): void
    {
        $this->assertSame('SELECT * FROM "cpu" WHERE "host" =~ ?', $this->table()->where('host', '=~', '^web')->toSql());
        $this->assertSame('SELECT * FROM "cpu" WHERE "host" =~ /^web/', $this->table()->where('host', '=~', '^web')->toRawSql());
        $this->assertSame('SELECT * FROM "cpu" WHERE "host" !~ /^web/', $this->table()->where('host', '!~', new Regex('^web'))->toRawSql());
    }

    #[UnitTest]
    public function testARegexValueTurnsEqualityIntoARegexMatch(): void
    {
        $this->assertSame('SELECT * FROM "cpu" WHERE "host" =~ /^web/', $this->table()->where('host', new Regex('^web'))->toRawSql());
        $this->assertSame('SELECT * FROM "cpu" WHERE "host" !~ /^web/', $this->table()->where('host', '!=', new Regex('^web'))->toRawSql());
        $this->assertSame('SELECT * FROM "cpu" WHERE "host" !~ /^web/', $this->table()->where('host', '<>', new Regex('^web'))->toRawSql());
    }

    #[UnitTest]
    public function testARegexLiteralEscapesEverySlashNotAlreadyEscaped(): void
    {
        $this->assertSame('/a\/b/', (new V1Grammar)->quoteRegex(new Regex('a/b')));
        $this->assertSame('/a\/b/', (new V1Grammar)->quoteRegex(new Regex('a\/b')));
        $this->assertSame('/^\d+$/', (new V1Grammar)->quoteRegex(new Regex('^\d+$')));
    }

    #[UnitTest]
    public function testGroupByTakesTagsAndTheWildcard(): void
    {
        $this->assertSame('SELECT * FROM "cpu" GROUP BY "host"', $this->table()->groupBy('host')->toSql());
        $this->assertSame('SELECT * FROM "cpu" GROUP BY "host", "region"', $this->table()->groupBy('host', 'region')->toSql());
        $this->assertSame('SELECT * FROM "cpu" GROUP BY "host", "region", "zone"', $this->table()->groupBy(['host', 'region'])->groupBy('zone')->toSql());
        $this->assertSame('SELECT * FROM "cpu" GROUP BY *', $this->table()->groupBy('*')->toSql());
    }

    #[UnitTest]
    public function testGroupByTimeTakesAnIntervalAndAnOffset(): void
    {
        $this->assertSame('SELECT * FROM "cpu" GROUP BY time(10m)', $this->table()->groupByTime('10m')->toSql());
        $this->assertSame('SELECT * FROM "cpu" GROUP BY time(1h, -15m)', $this->table()->groupByTime('1h', '-15m')->toSql());
        $this->assertSame('SELECT * FROM "cpu" GROUP BY time(1d), "host"', $this->table()->groupByTime('1d')->groupBy('host')->toSql());
    }

    #[UnitTest]
    #[DataProvider('durations')]
    public function testGroupByTimeAcceptsEveryDurationUnit(string $duration): void
    {
        $this->assertSame("SELECT * FROM \"cpu\" GROUP BY time({$duration})", $this->table()->groupByTime($duration)->toSql());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function durations(): array
    {
        $durations = ['100ns', '10u', '10µ', '250ms', '30s', '10m', '1h', '7d', '2w'];

        return array_combine($durations, array_map(fn (string $duration): array => [$duration], $durations));
    }

    #[UnitTest]
    #[DataProvider('invalidDurations')]
    public function testGroupByTimeRefusesAnythingButADurationLiteral(string $interval, ?string $offset, string $invalid): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs("[{$invalid}] is not an InfluxQL duration literal, such as 10m or 1h.");

        $this->table()->groupByTime($interval, $offset);
    }

    /**
     * @return array<string, array{string, ?string, string}>
     */
    public static function invalidDurations(): array
    {
        return [
            'words' => ['ten minutes', null, 'ten minutes'],
            'no unit' => ['10', null, '10'],
            'unknown unit' => ['10y', null, '10y'],
            'injection' => ['10m) ; DROP DATABASE "x"', null, '10m) ; DROP DATABASE "x"'],
            'bad offset' => ['1h', '15 m', '15 m'],
        ];
    }

    #[UnitTest]
    public function testARawGroupByIsEmbeddedAsWrittenWithItsBindings(): void
    {
        $this->assertSame('SELECT * FROM "cpu" GROUP BY /^h/', $this->table()->groupByRaw('?', [new Regex('^h')])->toRawSql());
    }

    #[UnitTest]
    #[DataProvider('fills')]
    public function testFillCompilesAKeywordOrANumber(float|int|string $value, string $expected): void
    {
        $this->assertSame(
            "SELECT MEAN(\"value\") FROM \"cpu\" GROUP BY time(1h) fill({$expected})",
            $this->table()->selectRaw('MEAN("value")')->groupByTime('1h')->fill($value)->toSql(),
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
            'previous' => ['previous', 'previous'],
            'linear, any case' => ['LINEAR', 'linear'],
            'zero' => [0, '0'],
            'negative integer' => [-1, '-1'],
            'float' => [0.5, '0.5'],
            'float needing no exponent' => [1.0E+20, '100000000000000000000.0'],
            'numeric string' => ['2.5', '2.5'],
            'negative numeric string' => ['-3', '-3'],
        ];
    }

    #[UnitTest]
    public function testFillRefusesAWordThatIsNotAFillKeyword(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('fill() takes a number or one of none, null, previous, linear, not [zero].');

        $this->table()->fill('zero');
    }

    #[UnitTest]
    public function testOrderByCompilesTimeInEitherDirection(): void
    {
        $this->assertSame('SELECT * FROM "cpu" ORDER BY "time" ASC', $this->table()->orderBy()->toSql());
        $this->assertSame('SELECT * FROM "cpu" ORDER BY "time" DESC', $this->table()->orderBy('time', 'desc')->toSql());
        $this->assertSame('SELECT * FROM "cpu" ORDER BY "time" DESC', $this->table()->orderBy('time', 'DESC')->toSql());
        $this->assertSame('SELECT * FROM "cpu" ORDER BY "time" DESC', $this->table()->orderByDesc()->toSql());
        $this->assertSame('SELECT * FROM "cpu" ORDER BY "time" DESC', $this->table()->latest()->toSql());
        $this->assertSame('SELECT * FROM "cpu" ORDER BY "time" ASC', $this->table()->oldest()->toSql());
        $this->assertSame('SELECT * FROM "cpu" ORDER BY "time" DESC', $this->table()->orderBy('time', SortDirection::Descending)->toSql());
        $this->assertSame('SELECT * FROM "cpu" ORDER BY "time" ASC', $this->table()->orderBy('time', SortDirection::Ascending)->toSql());
        $this->assertSame('SELECT * FROM "cpu" ORDER BY time DESC', $this->table()->orderBy(new Expression('time'), 'desc')->toSql());
    }

    #[UnitTest]
    public function testOrderByRefusesAnyColumnButTime(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('InfluxQL sorts by time only; orderBy() takes "time" or a raw expression.');

        $this->table()->orderBy('value');
    }

    #[UnitTest]
    public function testOrderByRefusesAnUnknownDirection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Order direction must be a SortDirection, "asc" or "desc".');

        $this->table()->orderBy('time', 'sideways');
    }

    #[UnitTest]
    public function testARawOrderIsEmbeddedAsWrittenWithItsBindings(): void
    {
        $this->assertSame('SELECT * FROM "cpu" ORDER BY time DESC', $this->table()->orderByRaw('time DESC')->toSql());
        $this->assertSame('SELECT * FROM "cpu" ORDER BY time DESC', $this->table()->orderByRaw('? DESC', [new Expression('time')])->toRawSql());
    }

    #[UnitTest]
    public function testReorderReplacesEveryOrder(): void
    {
        $this->assertSame('SELECT * FROM "cpu"', $this->table()->orderByDesc()->orderByRaw('time DESC', [])->reorder()->toSql());
        $this->assertSame('SELECT * FROM "cpu" ORDER BY "time" DESC', $this->table()->orderBy()->reorder('time', 'desc')->toSql());
    }

    #[UnitTest]
    public function testAnEmptyOrderListCompilesToNothing(): void
    {
        $this->assertSame('SELECT * FROM "cpu"', $this->table()->orderBy()->cloneWithout(['orders'])->toSql());
    }

    #[UnitTest]
    public function testLimitAndOffsetCountPoints(): void
    {
        $this->assertSame('SELECT * FROM "cpu" LIMIT 10 OFFSET 20', $this->table()->limit(10)->offset(20)->toSql());
        $this->assertSame('SELECT * FROM "cpu" LIMIT 10 OFFSET 20', $this->table()->take(10)->skip(20)->toSql());
        $this->assertSame('SELECT * FROM "cpu" LIMIT 25 OFFSET 50', $this->table()->forPage(3, 25)->toSql());
        $this->assertSame('SELECT * FROM "cpu" LIMIT 15 OFFSET 0', $this->table()->forPage(1)->toSql());
    }

    #[UnitTest]
    public function testANegativeLimitIsIgnoredAndANullOneClearsIt(): void
    {
        $this->assertSame('SELECT * FROM "cpu" LIMIT 10', $this->table()->limit(10)->limit(-1)->toSql());
        $this->assertSame('SELECT * FROM "cpu"', $this->table()->limit(10)->limit(null)->toSql());
        $this->assertSame('SELECT * FROM "cpu" OFFSET 0', $this->table()->offset(-5)->toSql());
        $this->assertSame('SELECT * FROM "cpu" OFFSET 0', $this->table()->offset(null)->toSql());
    }

    #[UnitTest]
    public function testSlimitAndSoffsetCountSeries(): void
    {
        $this->assertSame('SELECT * FROM "cpu" GROUP BY * SLIMIT 2 SOFFSET 1', $this->table()->groupBy('*')->slimit(2)->soffset(1)->toSql());
        $this->assertSame('SELECT * FROM "cpu" SLIMIT 2', $this->table()->slimit(2)->slimit(-1)->toSql());
        $this->assertSame('SELECT * FROM "cpu"', $this->table()->slimit(2)->slimit(null)->toSql());
        $this->assertSame('SELECT * FROM "cpu" SOFFSET 0', $this->table()->soffset(-1)->toSql());
    }

    #[UnitTest]
    public function testTzLocalisesTheReturnedTimestamps(): void
    {
        $this->assertSame('SELECT * FROM "cpu" tz(\'America/Chicago\')', $this->table()->tz('America/Chicago')->toSql());
    }

    #[UnitTest]
    public function testTzRefusesAnUnknownTimeZone(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('[Nowhere/Land] is not a time zone name.');

        $this->table()->tz('Nowhere/Land');
    }

    #[UnitTest]
    public function testEveryClauseComesInTheOrderInfluxqlReadsIt(): void
    {
        $query = $this->builder()
            ->tz('Europe/Amsterdam')
            ->soffset(1)
            ->slimit(2)
            ->offset(5)
            ->limit(10)
            ->orderByDesc()
            ->fill('previous')
            ->groupBy('region')
            ->groupByTime('1h')
            ->where('host', 'web1')
            ->where('time', '>=', new Expression('now() - 1d'))
            ->from('telegraf.autogen.cpu')
            ->into('telegraf.autogen.cpu_hourly')
            ->selectRaw('MEAN("usage_user") AS "mean"');

        $this->assertSame(
            'SELECT MEAN("usage_user") AS "mean" INTO "telegraf"."autogen"."cpu_hourly" FROM "telegraf"."autogen"."cpu"'
            . ' WHERE "host" = \'web1\' AND "time" >= now() - 1d GROUP BY "region", time(1h) fill(previous)'
            . ' ORDER BY "time" DESC LIMIT 10 OFFSET 5 SLIMIT 2 SOFFSET 1 tz(\'Europe/Amsterdam\')',
            $query->toRawSql(),
        );
    }

    #[UnitTest]
    public function testBindingsFollowTheOrderOfThePlaceholdersTheyFill(): void
    {
        $query = $this->builder()
            ->orderByRaw('? DESC', [new Expression('time')])
            ->groupByRaw('?', [new Regex('^h')])
            ->where('host', 'web1')
            ->from(new Regex('^cpu'))
            ->selectRaw('"value" * ? AS "scaled"', [2]);

        $this->assertSame('SELECT "value" * ? AS "scaled" FROM ? WHERE "host" = ? GROUP BY ? ORDER BY ? DESC', $query->toSql());
        $this->assertSame('SELECT "value" * 2 AS "scaled" FROM /^cpu/ WHERE "host" = \'web1\' GROUP BY /^h/ ORDER BY time DESC', $query->toRawSql());
    }

    #[UnitTest]
    public function testAnAggregateIsAliasedSoItCanBeReadBack(): void
    {
        $query = $this->table()->select('ignored');
        $query->aggregate = ['function' => 'count', 'columns' => ['value']];

        $this->assertSame('SELECT COUNT("value") AS "aggregate" FROM "cpu"', $query->toSql());
    }

    #[UnitTest]
    public function testAWildcardAggregateIsLeftUnaliased(): void
    {
        $query = $this->table();
        $query->aggregate = ['function' => 'count', 'columns' => ['*']];

        $this->assertSame('SELECT COUNT(*) FROM "cpu"', $query->toSql());
    }

    #[UnitTest]
    public function testADistinctAggregateCountsDistinctValues(): void
    {
        $query = $this->table()->distinct();
        $query->aggregate = ['function' => 'count', 'columns' => ['host']];

        $this->assertSame('SELECT COUNT(DISTINCT("host")) AS "aggregate" FROM "cpu"', $query->toSql());

        $query->aggregate = ['function' => 'count', 'columns' => ['*']];

        $this->assertSame('SELECT COUNT(*) FROM "cpu"', $query->toSql());
    }

    #[UnitTest]
    public function testAnAggregateTakesExtraArgumentsAsExpressions(): void
    {
        $query = $this->table();
        $query->aggregate = ['function' => 'percentile', 'columns' => ['value', new Expression('95')]];

        $this->assertSame('SELECT PERCENTILE("value", 95) AS "aggregate" FROM "cpu"', $query->toSql());
    }

    #[UnitTest]
    public function testCompilingLeavesTheColumnsAsTheyWere(): void
    {
        $query = $this->table();

        $query->toSql();

        $this->assertNull($query->columns);
    }

    #[UnitTest]
    public function testDeleteTakesTheMeasurementAndTheWhereClauseOnly(): void
    {
        $grammar = new V1Grammar;

        $this->assertSame('DELETE FROM "cpu"', $grammar->compileDelete($this->table()));
        $this->assertSame(
            'DELETE FROM "cpu" WHERE "host" = ? AND "time" < ?',
            $grammar->compileDelete($this->table()->select('value')->where('host', 'web1')->where('time', '<', new DateTimeImmutable)->orderByDesc()),
        );
        $this->assertSame('DELETE FROM ?', $grammar->compileDelete($this->builder()->from(new Regex('^cpu'))));
        $this->assertSame('DELETE FROM "disk.io"', $grammar->compileDelete($this->builder()->from(new Expression('"disk.io"'))));
    }

    /**
     * InfluxQL's DELETE has no LIMIT, OFFSET, SLIMIT or SOFFSET, and dropping one would delete more points than the query selects.
     */
    #[UnitTest]
    #[DataProvider('narrowedDeletes')]
    public function testDeleteRefusesALimitOffsetSlimitOrSoffset(Closure $narrow): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('An InfluxQL DELETE takes no limit, offset, slimit or soffset; narrow it with where() instead.');

        (new V1Grammar)->compileDelete($narrow($this->table()->where('host', 'web1')));
    }

    /**
     * @return array<string, array{Closure(Builder): Builder}>
     */
    public static function narrowedDeletes(): array
    {
        return [
            'a limit' => [static fn (Builder $query): Builder => $query->limit(5)],
            'a limit of zero' => [static fn (Builder $query): Builder => $query->limit(0)],
            'an offset' => [static fn (Builder $query): Builder => $query->offset(5)],
            'a slimit' => [static fn (Builder $query): Builder => $query->slimit(2)],
            'a soffset' => [static fn (Builder $query): Builder => $query->soffset(1)],
        ];
    }

    #[UnitTest]
    public function testDeleteNeedsAMeasurement(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('An InfluxQL DELETE needs a measurement; call from() first.');

        (new V1Grammar)->compileDelete($this->builder()->where('host', 'web1'));
    }

    /**
     * InfluxDB's parser refuses a database or retention policy in a DELETE,
     * `database not supported` or `retention policy not supported`, on 1.x
     * and 2.x alike.
     */
    #[UnitTest]
    #[DataProvider('qualifiedMeasurements')]
    public function testDeleteRefusesAQualifiedMeasurement(string $measurement): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs("An InfluxQL DELETE cannot name a database or retention policy, as [{$measurement}] does; pass the measurement alone, or as an Expression if the dot is part of its name.");

        (new V1Grammar)->compileDelete($this->table($measurement)->where('host', 'web1'));
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
    public function testStringLiteralsEscapeQuotesBackslashesAndNewlinesWithABackslash(): void
    {
        $grammar = new V1Grammar;

        $this->assertSame("'it\\'s'", $grammar->quoteString("it's"));
        $this->assertSame("'a\\\\b'", $grammar->quoteString('a\b'));
        $this->assertSame("'a\\nb'", $grammar->quoteString("a\nb"));
        $this->assertSame("'a', 'b\\'c'", $grammar->quoteString(['a', "b'c"]));
    }

    #[UnitTest]
    #[DataProvider('literals')]
    public function testEveryBindableValueHasALiteral(mixed $value, string $literal): void
    {
        $this->assertSame($literal, (new V1Grammar)->escape($value));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function literals(): array
    {
        return [
            'string' => ['web1', "'web1'"],
            'string with a quote' => ["o'clock", "'o\\'clock'"],
            'integer' => [42, '42'],
            'negative integer' => [-7, '-7'],
            'true' => [true, 'true'],
            'false' => [false, 'false'],
            'float' => [0.64, '0.64'],
            'whole float keeps its point' => [1.0, '1.0'],
            'negative zero' => [-0.0, '-0.0'],
            'float below 1e-4' => [1.5E-7, '0.00000015'],
            'negative float below 1e-4' => [-2.5E-7, '-0.00000025'],
            'float just below 1e-4' => [1.0E-5, '0.00001'],
            'float at 1e16, written out by PHP' => [1.0E+16, '10000000000000000.0'],
            'float from 1e17' => [1.0E+17, '100000000000000000.0'],
            'float above 2^63' => [1.5E+20, '150000000000000000000.0'],
            'float with every digit before the point' => [1.2345678901234567E+19, '12345678901234567000.0'],
            'smallest float' => [5.0E-324, '0.' . str_repeat('0', 323) . '5'],
            'date' => [new DateTimeImmutable('2024-06-01 12:34:56.789', new DateTimeZone('America/New_York')), "'2024-06-01T16:34:56.789000Z'"],
            'regex' => [new Regex('^web\d+$'), '/^web\d+$/'],
            'expression' => [new Expression('now() - 1h'), 'now() - 1h'],
            'numeric expression' => [new Expression(5), '5'],
            'stringable' => [new class implements Stringable {
                public function __toString(): string
                {
                    return "it's";
                }
            }, "'it\\'s'"],
        ];
    }

    #[UnitTest]
    #[DataProvider('valuesWithoutALiteral')]
    public function testAValueWithoutALiteralIsRefused(mixed $value, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs($message);

        (new V1Grammar)->escape($value);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function valuesWithoutALiteral(): array
    {
        return [
            'null' => [null, 'InfluxQL has no null literal; a field or tag cannot be compared with null.'],
            'NAN' => [NAN, 'InfluxQL has no literal for NAN or INF.'],
            'INF' => [INF, 'InfluxQL has no literal for NAN or INF.'],
            '-INF' => [-INF, 'InfluxQL has no literal for NAN or INF.'],
            'array' => [['a'], 'An array cannot be embedded in InfluxQL; use whereIn() or whereBetween().'],
            'object' => [new stdClass, 'A value of type stdClass cannot be embedded in InfluxQL.'],
        ];
    }

    #[UnitTest]
    public function testSubstitutionSkipsPlaceholdersInsideLiteralsAndIdentifiers(): void
    {
        $sql = <<<'SQL'
            SELECT "a?b" FROM "cpu" WHERE "host" = 'it\'s ?' AND "tag\"?" = ? AND "value" > ?
            SQL;

        $this->assertSame(
            <<<'SQL'
                SELECT "a?b" FROM "cpu" WHERE "host" = 'it\'s ?' AND "tag\"?" = 'x' AND "value" > 5
                SQL,
            (new V1Grammar)->substituteBindingsIntoRawSql($sql, ['x', 5]),
        );
    }

    #[UnitTest]
    public function testSubstitutionLeavesAPlaceholderWithNoBindingAsItIs(): void
    {
        $this->assertSame('SELECT * FROM "cpu" WHERE "a" = 1 AND "b" = ?', (new V1Grammar)->substituteBindingsIntoRawSql('SELECT * FROM "cpu" WHERE "a" = ? AND "b" = ?', [1]));
    }

    #[UnitTest]
    public function testSubstitutionCopiesAnUnterminatedLiteralThatEndsInABackslash(): void
    {
        $this->assertSame("'abc\\", (new V1Grammar)->substituteBindingsIntoRawSql("'abc\\", ['unused']));
    }

    #[UnitTest]
    public function testSubstitutionReindexesTheBindings(): void
    {
        $this->assertSame('1 2', (new V1Grammar)->substituteBindingsIntoRawSql('? ?', ['first' => 1, 'second' => 2]));
    }

    #[UnitTest]
    public function testTheHelpersWrapQuoteAndPlaceholdLikeTheSqlGrammar(): void
    {
        $grammar = new V1Grammar;

        $this->assertSame(['"a"', 'b'], $grammar->wrapArray(['x' => 'a', 'y' => new Expression('b')]));
        $this->assertSame('"a", "b"', $grammar->columnize(['a', 'b']));
        $this->assertSame('?, now()', $grammar->parameterize(['a', new Expression('now()')]));
        $this->assertSame('?', $grammar->parameter(new Regex('x')));
        $this->assertSame('?', $grammar->wrapMeasurement(new Regex('x')));
        $this->assertSame('"a.b"', $grammar->wrapMeasurement(new Expression('"a.b"')));
        $this->assertTrue($grammar->isExpression(new Expression('x')));
        $this->assertFalse($grammar->isExpression('x'));
        $this->assertSame(5, $grammar->getValue(new Expression(5)));
        $this->assertSame('x', $grammar->getValue('x'));
        $this->assertSame(['=', '<', '>', '<=', '>=', '<>', '!=', '=~', '!~'], $grammar->getOperators());
        $this->assertSame('Y-m-d\TH:i:s.u\Z', $grammar->getDateFormat());
        $this->assertSame('2024-01-01T00:00:00.000000Z', $grammar->formatDateTime(new DateTime('2023-12-31 19:00:00', new DateTimeZone('America/New_York'))));
    }

    #[UnitTest]
    public function testV2CompilesEveryClauseButIntoAsV1Does(): void
    {
        $query = fn (array $config): Builder => $this->builder($config)
            ->selectRaw('MEAN("usage_user") AS "mean"')
            ->from('telegraf.autogen.cpu')
            ->where('host', '=~', '^web')
            ->where('time', '>=', new DateTimeImmutable('2024-01-01T00:00:00Z'))
            ->groupByTime('1h')
            ->groupBy('region')
            ->fill('previous')
            ->orderByDesc()
            ->limit(10)
            ->offset(5)
            ->slimit(2)
            ->soffset(1)
            ->tz('Europe/Amsterdam');

        $this->assertInstanceOf(V2Grammar::class, $query(['version' => 'v2'])->getGrammar());
        $this->assertSame(
            'SELECT MEAN("usage_user") AS "mean" FROM "telegraf"."autogen"."cpu" WHERE "host" =~ /^web/ AND "time" >= \'2024-01-01T00:00:00.000000Z\''
            . ' GROUP BY time(1h), "region" fill(previous) ORDER BY "time" DESC LIMIT 10 OFFSET 5 SLIMIT 2 SOFFSET 1 tz(\'Europe/Amsterdam\')',
            $query(['version' => 'v2'])->toRawSql(),
        );
        $this->assertSame($query(['version' => 'v1'])->toRawSql(), $query(['version' => 'v2'])->toRawSql());
    }

    #[UnitTest]
    public function testV2RefusesSelectInto(): void
    {
        $query = $this->table(config: ['version' => 'v2'])->selectRaw('MEAN("value")')->into('cpu_hourly')->groupByTime('1h');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('InfluxDB 2.x does not support SELECT ... INTO; downsample with a task instead.');

        $query->toSql();
    }

    /**
     * InfluxDB 2.x runs a DELETE on the database's default retention policy,
     * which is also the one a connection that addresses no policy reads.
     */
    #[UnitTest]
    #[DataProvider('connectionsWithoutARetentionPolicy')]
    public function testV2CompilesADeleteOnAConnectionThatAddressesNoRetentionPolicy(array $config): void
    {
        $query = $this->table(config: ['version' => 'v2', ...$config])->where('host', 'web1');

        $this->assertNull($query->getConnection()->getRetentionPolicy());
        $this->assertSame('DELETE FROM "cpu" WHERE "host" = ?', $query->getGrammar()->compileDelete($query));
        $this->assertSame('DELETE FROM ? WHERE "host" = ?', $query->getGrammar()->compileDelete($query->from(new Regex('^cpu'))));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function connectionsWithoutARetentionPolicy(): array
    {
        return [
            'a bucket named without a slash' => [['bucket' => 'telegraf']],
            'a database named alone' => [['bucket' => 'telegraf/autogen', 'influxql' => ['database' => 'telegraf']]],
        ];
    }

    /**
     * Where the connection addresses a retention policy, InfluxDB 2.x would
     * delete from the default one's bucket instead, or refuse the statement
     * when the database has no default mapping, as with `db/rp` buckets.
     */
    #[UnitTest]
    #[DataProvider('connectionsWithARetentionPolicy')]
    public function testV2RefusesADeleteOnAConnectionThatAddressesARetentionPolicy(array $config, string $policy): void
    {
        $query = $this->table(config: ['version' => 'v2', ...$config])->where('host', 'web1');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs("InfluxDB 2.x deletes only from the database's default retention policy, not from the connection's [{$policy}]; delete through the /api/v2/delete API instead.");

        $query->getGrammar()->compileDelete($query);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function connectionsWithARetentionPolicy(): array
    {
        return [
            'a db/rp bucket' => [['bucket' => 'telegraf/weekly'], 'weekly'],
            'a policy in the influxql block' => [['bucket' => 'telegraf', 'influxql' => ['retentionPolicy' => 'autogen']], 'autogen'],
        ];
    }

    #[UnitTest]
    public function testV1CompilesADeleteWhateverPolicyTheConnectionAddresses(): void
    {
        $query = $this->table(config: ['version' => 'v1', 'bucket' => 'telegraf/weekly'])->where('host', 'web1');

        $this->assertSame('weekly', $query->getConnection()->getRetentionPolicy());
        $this->assertSame('DELETE FROM "cpu" WHERE "host" = ?', $query->getGrammar()->compileDelete($query));
    }

    #[UnitTest]
    public function testV3CompilesEveryClauseButIntoSlimitAndSoffsetAsV1Does(): void
    {
        $query = fn (array $config): Builder => $this->builder($config)
            ->selectRaw('MEAN("usage_user") AS "mean"')
            ->from('telegraf.autogen.cpu')
            ->where('host', '=~', '^web')
            ->where('time', '>=', new DateTimeImmutable('2024-01-01T00:00:00Z'))
            ->groupByTime('1h')
            ->groupBy('region')
            ->fill('previous')
            ->orderByDesc()
            ->limit(10)
            ->offset(5)
            ->tz('Europe/Amsterdam');

        $this->assertInstanceOf(V3Grammar::class, $query(['version' => 'v3'])->getGrammar());
        $this->assertSame(
            'SELECT MEAN("usage_user") AS "mean" FROM "telegraf"."autogen"."cpu" WHERE "host" =~ /^web/ AND "time" >= \'2024-01-01T00:00:00.000000Z\''
            . ' GROUP BY time(1h), "region" fill(previous) ORDER BY "time" DESC LIMIT 10 OFFSET 5 tz(\'Europe/Amsterdam\')',
            $query(['version' => 'v3'])->toRawSql(),
        );
        $this->assertSame($query(['version' => 'v1'])->toRawSql(), $query(['version' => 'v3'])->toRawSql());
    }

    #[UnitTest]
    public function testV3RefusesSelectInto(): void
    {
        $query = $this->table(config: ['version' => 'v3'])->selectRaw('MEAN("value")')->into('cpu_hourly')->groupByTime('1h');

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
        $query = fn (): Builder => $this->table(config: ['version' => 'v3'])->groupBy('*');

        $this->assertSame('SELECT * FROM "cpu" GROUP BY *', $query()->slimit(0)->soffset(0)->toSql());
        $this->assertSame('SELECT * FROM "cpu" GROUP BY *', $query()->soffset(null)->toSql());
        $this->assertSame('SELECT * FROM "cpu" GROUP BY *', $query()->soffset(-1)->slimit(2)->slimit(null)->toSql());
        $this->assertSame('SELECT * FROM "cpu" GROUP BY * SLIMIT 0 SOFFSET 0', $this->table()->groupBy('*')->slimit(0)->soffset(0)->toSql());
    }

    #[UnitTest]
    #[DataProvider('seriesClausesInfluxdb3Refuses')]
    public function testV3RefusesAnySlimitOrSoffsetButZero(Closure $clause, string $message): void
    {
        $query = $clause($this->table(config: ['version' => 'v3'])->groupBy('*'));

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
            'slimit' => [static fn (Builder $query): Builder => $query->slimit(2), 'InfluxDB 3 does not support SLIMIT.'],
            'soffset' => [static fn (Builder $query): Builder => $query->soffset(1), 'InfluxDB 3 does not support SOFFSET.'],
        ];
    }

    #[UnitTest]
    #[DataProvider('deletesInfluxdb3Refuses')]
    public function testV3RefusesEveryDelete(Closure $delete): void
    {
        $query = $delete($this->builder(['version' => 'v3']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('InfluxDB 3 does not support DELETE; delete the table or the database instead.');

        $query->getGrammar()->compileDelete($query);
    }

    /**
     * @return array<string, array{Closure(Builder): Builder}>
     */
    public static function deletesInfluxdb3Refuses(): array
    {
        return [
            'a whole measurement' => [static fn (Builder $query): Builder => $query->from('cpu')],
            'a where clause' => [static fn (Builder $query): Builder => $query->from('cpu')->where('host', 'web1')],
            'a regex measurement' => [static fn (Builder $query): Builder => $query->from(new Regex('^cpu'))],
            'no measurement' => [static fn (Builder $query): Builder => $query],
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function builder(array $config = []): Builder
    {
        return $this->connection(config: $config)->query();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function table(string $measurement = 'cpu', array $config = []): Builder
    {
        return $this->connection(config: $config)->table($measurement);
    }
}
