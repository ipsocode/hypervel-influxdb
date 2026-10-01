<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\InfluxQL;

use Ipsocode\InfluxDB\InfluxQL\Grammars\Grammar;

/**
 * A raw InfluxQL fragment that the grammar embeds verbatim.
 *
 * It does not implement Hypervel\Contracts\Database\Query\Expression on purpose: the
 * contract's getValue() takes Hypervel\Database\Grammar, a PDO grammar unused here.
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
