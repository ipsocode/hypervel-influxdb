<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature\Sql;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Hypervel\Database\BinaryParameter;
use Hypervel\Database\Query\Builder;
use Hypervel\Foundation\Testing\Attributes\UnitTest;
use Ipsocode\InfluxDB\Sql\SqlConnection;
use Ipsocode\InfluxDB\Sql\SqlGrammar;
use Ipsocode\InfluxDB\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * What the SQL grammar compiles, and how it embeds each value at a placeholder found as DataFusion
 * reads the statement. The connection has no transport; SqlConnectionTest sends the statements.
 */
class SqlGrammarTest extends TestCase
{
    #[UnitTest]
    public function testTheConnectionCompilesWithTheSqlGrammar(): void
    {
        $this->assertInstanceOf(SqlGrammar::class, $this->connection()->getQueryGrammar());
        $this->assertInstanceOf(SqlGrammar::class, $this->table()->getGrammar());
    }

    #[UnitTest]
    public function testTheBuilderCompilesAsItDoesForPostgresql(): void
    {
        $this->assertSame(
            'select distinct on ("host") "host", "usage_user" from "cpu" where "host"::text ilike ? and ("host" ~ ?)::bool and "time"::date = ? and extract(hour from "time") = ? order by "host" asc, "time" desc limit 10 offset 5',
            $this->table()
                ->distinct('host')
                ->select('host', 'usage_user')
                ->whereLike('host', 'WEB%')
                ->where('host', '~', '^web')
                ->whereDate('time', '2024-01-01')
                ->whereRaw('extract(hour from "time") = ?', [0])
                ->orderBy('host')
                ->orderByDesc('time')
                ->limit(10)
                ->offset(5)
                ->toSql(),
        );
    }

    #[UnitTest]
    public function testIdentifiersAreDoubleQuotedWithTheirQuotesDoubled(): void
    {
        $this->assertSame('select "a""b", "c?d" from "iox"."cpu"', $this->connection()->table('iox.cpu')->select('a"b', 'c?d')->toSql());
    }

    #[UnitTest]
    public function testExistsSelectsARowOnlyWhenOneExists(): void
    {
        $query = $this->table()->where('host', 'web1');

        $this->assertSame(
            'select true as "exists" where exists(select * from "cpu" where "host" = ?)',
            $query->getGrammar()->compileExists($query),
        );
    }

    #[UnitTest]
    public function testALockCompilesToNothingUnlessItIsWrittenOut(): void
    {
        $this->assertSame('select * from "cpu"', $this->table()->lockForUpdate()->toSql());
        $this->assertSame('select * from "cpu"', $this->table()->sharedLock()->toSql());
        $this->assertSame('select * from "cpu" for update skip locked', $this->table()->lock('for update skip locked')->toSql());
    }

    #[UnitTest]
    public function testThereIsNoCountOfOpenConnectionsToAskFor(): void
    {
        $this->assertNull($this->connection()->getQueryGrammar()->compileThreadCount());
        $this->assertNull($this->connection()->threadCount());
    }

    #[UnitTest]
    public function testADateIsWrittenAsRfc3339WithItsOwnOffset(): void
    {
        $grammar = $this->connection()->getQueryGrammar();

        $this->assertSame('Y-m-d\TH:i:s.uP', $grammar->getDateFormat());
        $this->assertSame(
            ['2024-01-01T02:00:00.500000+02:00', '2023-12-31T19:00:00.000000-05:00', '2024-01-01T00:00:00.000000+00:00'],
            $this->connection()->prepareBindings([
                new DateTimeImmutable('2024-01-01 02:00:00.5', new DateTimeZone('+02:00')),
                new DateTime('2023-12-31 19:00:00', new DateTimeZone('America/New_York')),
                new DateTimeImmutable('2024-01-01T00:00:00Z'),
            ]),
        );
    }

    /**
     * Hypervel casts a boolean to 0 or 1 for PDO; DataFusion does not compare a boolean column with a number.
     */
    #[UnitTest]
    public function testABooleanStaysOneAndIsWrittenAsTrueOrFalse(): void
    {
        $connection = $this->connection();

        $this->assertSame([true, false, 1, 0, null, 'web1'], $connection->prepareBindings([true, false, 1, 0, null, 'web1']));
        $this->assertSame(
            'select * from "cpu" where "ok" = TRUE and "stale" = FALSE and "count" = 1',
            $this->table()->where('ok', true)->where('stale', false)->where('count', 1)->toRawSql(),
        );
    }

    #[UnitTest]
    #[DataProvider('literals')]
    public function testEveryBindableValueHasALiteral(mixed $value, string $literal): void
    {
        $this->assertSame($literal, $this->connection()->getQueryGrammar()->escape($value));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function literals(): array
    {
        return [
            'string' => ['web1', "'web1'"],
            'a quote is doubled' => ["it's", "'it''s'"],
            'a backslash is itself' => ['C:\temp\\', "'C:\\temp\\'"],
            'a newline is itself' => ["a\nb", "'a\nb'"],
            'unicode' => ['café 😀', "'café 😀'"],
            'integer' => [42, '42'],
            'float' => [0.64, '0.64'],
            'float with an exponent' => [1.0E+25, '1.0E+25'],
            'true' => [true, 'TRUE'],
            'false' => [false, 'FALSE'],
            'null' => [null, 'null'],
        ];
    }

    #[UnitTest]
    #[DataProvider('valuesWithoutALiteral')]
    public function testAValueWithoutALiteralIsRefused(mixed $value, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs($message);

        $this->connection()->getQueryGrammar()->substituteBindingsIntoRawSql('select ?', [$value]);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function valuesWithoutALiteral(): array
    {
        return [
            'a null byte' => ["a\0b", 'Strings with null bytes cannot be escaped. Use the binary escape option.'],
            'invalid UTF-8' => ["\xB1\x31", 'Strings with invalid UTF-8 byte sequences cannot be escaped.'],
            'binary' => [new BinaryParameter("\x00\x01"), 'The database connection does not support escaping binary values.'],
        ];
    }

    #[UnitTest]
    public function testAResourceIsEmbeddedAsTheStringPhpGivesIt(): void
    {
        $open = fopen('php://memory', 'r');
        $closed = fopen('php://memory', 'r');
        fclose($closed);

        try {
            $this->assertMatchesRegularExpression(
                "/^select 'Resource id #\\d+', 'Resource id #\\d+'$/",
                $this->connection()->getQueryGrammar()->substituteBindingsIntoRawSql('select ?, ?', [$open, $closed]),
            );
        } finally {
            fclose($open);
        }
    }

    #[UnitTest]
    #[DataProvider('statements')]
    public function testThePlaceholdersAreFoundAsDataFusionReadsTheStatement(string $sql, array $bindings, string $expected): void
    {
        $this->assertSame($expected, $this->connection()->getQueryGrammar()->substituteBindingsIntoRawSql($sql, $bindings));
    }

    /**
     * @return array<string, array{string, array<string, mixed>|list<mixed>, string}>
     */
    public static function statements(): array
    {
        return [
            'placeholders in order' => [
                'select * from "cpu" where "host" = ? and "value" > ?',
                ['web1', 0.5],
                'select * from "cpu" where "host" = \'web1\' and "value" > 0.5',
            ],
            'not in a string, where a doubled quote is an escape' => [
                "select 'it''s ?' where x = ?",
                [1],
                "select 'it''s ?' where x = 1",
            ],
            'a backslash in a string is itself, not an escape' => [
                "select 'C:\\' as p where x = ?",
                [1],
                "select 'C:\\' as p where x = 1",
            ],
            'not in a quoted identifier, where a doubled quote is an escape' => [
                'select "a?b", "it\'s", "c""?" from "cpu" where x = ?',
                [1],
                'select "a?b", "it\'s", "c""?" from "cpu" where x = 1',
            ],
            'in an E string a backslash escapes' => [
                "select E'it\\'s ?', e'\\\\' as b where x = ?",
                [1],
                "select E'it\\'s ?', e'\\\\' as b where x = 1",
            ],
            'in an E string a doubled quote escapes too' => [
                "select E'a''b\\'?' where x = ?",
                [1],
                "select E'a''b\\'?' where x = 1",
            ],
            'an E string at the start' => [
                "E'\\'?' = ?",
                [1],
                "E'\\'?' = 1",
            ],
            'an E string after punctuation' => [
                "select (E'\\'') where x = ?",
                [1],
                "select (E'\\'') where x = 1",
            ],
            'a word ending in E does not start an E string' => [
                "select the'\\' as x where y = ?",
                [1],
                "select the'\\' as x where y = 1",
            ],
            'not in a line comment' => [
                "select ? -- is it? isn't it?\nwhere x = ?",
                [1, 2],
                "select 1 -- is it? isn't it?\nwhere x = 2",
            ],
            'not in a block comment' => [
                "select ? /* what's that? */ where x = ?",
                [1, 2],
                "select 1 /* what's that? */ where x = 2",
            ],
            'a doubled question mark is a literal one' => [
                'select ?? as q, ??? where x = ?',
                [1, 2],
                'select ? as q, ?1 where x = 2',
            ],
            'a value is embedded as written and not read again' => [
                'select ? as a, ? as b',
                ["it's ?? -- /* E'", 2],
                "select 'it''s ?? -- /* E''' as a, 2 as b",
            ],
            'keyed bindings are taken in order' => [
                'select ?, ?',
                ['first' => 1, 'second' => 2],
                'select 1, 2',
            ],
            'a placeholder with no binding is left as it is' => [
                'select ? where x = ?',
                [1],
                'select 1 where x = ?',
            ],
            'an unterminated string is copied to the end' => [
                "select 'abc ? where x = ?",
                [1],
                "select 'abc ? where x = ?",
            ],
            'an unterminated identifier is copied to the end' => [
                'select "abc ?',
                [1],
                'select "abc ?',
            ],
            'an unterminated E string ending in a backslash is copied to the end' => [
                "select E'abc\\",
                [1],
                "select E'abc\\",
            ],
            'a line comment at the end' => [
                'select ? -- done?',
                [1],
                'select 1 -- done?',
            ],
            'an unterminated block comment is copied to the end' => [
                'select ? /* done?',
                [1],
                'select 1 /* done?',
            ],
            'nothing to substitute' => [
                '',
                [1],
                '',
            ],
        ];
    }

    #[UnitTest]
    public function testTheRawSqlIsTheStatementWithItsValuesEmbedded(): void
    {
        $query = $this->table()
            ->select('host', 'note')
            ->where('host', "it's")
            ->where('path', 'C:\temp\\')
            ->where('ok', true)
            ->whereNull('note')
            ->where('value', '>', 0.5)
            ->where('time', '>=', new DateTimeImmutable('2024-01-01 02:00:00', new DateTimeZone('+02:00')));

        $this->assertSame(
            'select "host", "note" from "cpu" where "host" = \'it\'\'s\' and "path" = \'C:\temp\\\' and "ok" = TRUE and "note" is null and "value" > 0.5 and "time" >= \'2024-01-01T02:00:00.000000+02:00\'',
            $query->toRawSql(),
        );
    }

    /**
     * A connection with no transport, which has nothing to send a statement through.
     */
    private function connection(): SqlConnection
    {
        return new SqlConnection(null, 'telegraf');
    }

    private function table(): Builder
    {
        return $this->connection()->table('cpu');
    }
}
