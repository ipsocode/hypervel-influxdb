<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\InfluxQL;

/**
 * A regular expression literal: the operand of =~ and !~, or a measurement pattern.
 *
 * InfluxQL writes it as /pattern/, neither a string nor an identifier, so it travels
 * through the bindings as this type. The pattern is given without delimiters.
 */
final class Regex
{
    public function __construct(
        public readonly string $pattern,
    ) {
    }
}
