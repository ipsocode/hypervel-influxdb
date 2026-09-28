<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\InfluxQL;

use Ipsocode\InfluxDB\InfluxQL\Grammars\Grammar;

/**
 * A raw InfluxQL fragment that the grammar embeds verbatim.
 *
 * The InfluxQL twin of Hypervel\Database\Query\Expression. It does not implement
 * Hypervel\Contracts\Database\Query\Expression on purpose: that contract types
 * getValue() to the concrete Hypervel\Database\Grammar, a PDO grammar this
 * package has no use for.
 *
 * @template TValue of string|int|float
 */
class Expression
{
    /**
     * Create a new raw query expression.
     *
     * @param TValue $value
     */
    public function __construct(
        protected string|int|float $value,
    ) {
    }

    /**
     * Get the value of the expression.
     *
     * @return TValue
     */
    public function getValue(Grammar $grammar): string|int|float
    {
        return $this->value;
    }
}
