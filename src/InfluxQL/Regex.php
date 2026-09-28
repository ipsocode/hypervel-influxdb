<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\InfluxQL;

/**
 * A regular expression literal: the operand of =~ and !~, or a measurement pattern.
 *
 * InfluxQL writes these as /pattern/, which is neither a string nor an
 * identifier, so a plain PHP string could not say which quoting it wants.
 * Wrapping the pattern in this type lets it travel through the query bindings
 * and be rendered by the grammar only when the statement is assembled.
 *
 * The pattern is given without delimiters; the grammar adds them and escapes
 * any unescaped slash inside it.
 */
final class Regex
{
    public function __construct(
        public readonly string $pattern,
    ) {
    }
}
