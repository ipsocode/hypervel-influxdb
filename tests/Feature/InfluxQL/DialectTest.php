<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature\InfluxQL;

use Hypervel\Database\Query\Expression as QueryExpression;
use Hypervel\Foundation\Testing\Attributes\UnitTest;
use InvalidArgumentException;
use Ipsocode\InfluxDB\InfluxQL\Dialect;
use Ipsocode\InfluxDB\InfluxQL\Expression;
use Ipsocode\InfluxDB\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The Dialect paths no grammar reaches: a raw Expression, literals handed over already escaped, and the
 * type hint stripped from a plucked column's name. GrammarTest covers the rest through a grammar.
 */
class DialectTest extends TestCase
{
    #[UnitTest]
    #[DataProvider('expressions')]
    public function testARawExpressionIsTheGrammarsToEmbedAndIsRefusedHere(object $expression): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs(sprintf('A value of type %s cannot be embedded in InfluxQL.', $expression::class));

        Dialect::escape($expression);
    }

    /**
     * @return array<string, array{object}>
     */
    public static function expressions(): array
    {
        return [
            'the InfluxQL builder\'s' => [new Expression('now() - 1h')],
            'Hypervel\'s query builder\'s' => [new QueryExpression('now() - 1h')],
        ];
    }

    #[UnitTest]
    public function testSubstitutionEmbedsTheLiteralsAsGivenWithoutScanningThemAgain(): void
    {
        $this->assertSame(
            'SELECT * FROM "cpu" WHERE "time" > now() - 1h AND "host" = \'a?b\' AND "value" > ?',
            Dialect::substituteBindings(
                'SELECT * FROM "cpu" WHERE "time" > ? AND "host" = ? AND "value" > ?',
                ['now() - 1h', "'a?b'"],
            ),
        );
    }

    #[UnitTest]
    public function testSubstitutionTakesTheLiteralsInOrderWhateverTheirKeys(): void
    {
        $this->assertSame("'b' 'a'", Dialect::substituteBindings('? ?', [1 => "'b'", 0 => "'a'"]));
    }

    /**
     * InfluxDB returns a column selected with a type hint under its name
     * alone, so the name the builder reads a plucked column back under has to
     * be what quoteIdentifier() keeps inside the quotes.
     */
    #[UnitTest]
    #[DataProvider('typeHints')]
    public function testATypeHintIsStrippedExactlyWhereQuoteIdentifierLeavesItOutsideTheQuotes(string $column, string $name, string $quoted): void
    {
        $this->assertSame($name, Dialect::stripTypeHint($column));
        $this->assertSame($quoted, Dialect::quoteIdentifier($column));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function typeHints(): array
    {
        return [
            'a tag' => ['host::tag', 'host', '"host"::tag'],
            'a field' => ['value::field', 'value', '"value"::field'],
            'a cast, in any case' => ['load::FLOAT', 'load', '"load"::float'],
            'a name with a dot' => ['disk.used::integer', 'disk.used', '"disk.used"::integer'],
            'the last of two hints' => ['value::field::float', 'value::field', '"value"::field::float'],
            'no hint' => ['host', 'host', '"host"'],
            'a suffix that is not a hint' => ['host::name', 'host::name', '"host::name"'],
            'a hint with no name before it' => ['::tag', '::tag', '"::tag"'],
        ];
    }
}
