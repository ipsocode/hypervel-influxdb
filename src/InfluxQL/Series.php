<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\InfluxQL;

use stdClass;

/**
 * One series of a statement result: the rows of one measurement under one tag set.
 *
 * InfluxDB answers an InfluxQL statement with a list of series rather than a
 * flat row set — one per measurement, split further by the GROUP BY tags. This
 * keeps that shape intact for callers who want it, and rows() flattens it
 * into the per-row objects the query builder returns.
 */
final class Series
{
    /**
     * @param string $name the measurement the rows came from
     * @param array<string, null|string> $tags the GROUP BY tag values shared by every row
     * @param list<string> $columns the column names, `time` first
     * @param list<list<mixed>> $values one list per row, in column order
     * @param bool $partial whether the server truncated this series (`max-row-limit`)
     */
    public function __construct(
        public readonly string $name,
        public readonly array $tags,
        public readonly array $columns,
        public readonly array $values,
        public readonly bool $partial = false,
    ) {
    }

    /**
     * Build a series from one decoded `series` entry of a /query response.
     *
     * @param array<string, mixed> $series
     */
    public static function fromArray(array $series): self
    {
        return new self(
            (string) ($series['name'] ?? ''),
            (array) ($series['tags'] ?? []),
            array_values((array) ($series['columns'] ?? [])),
            array_values((array) ($series['values'] ?? [])),
            (bool) ($series['partial'] ?? false),
        );
    }

    /**
     * Get the rows as objects keyed by column name, with the series tags appended.
     *
     * The tags come after the columns so that a column always wins a name
     * clash, and so the first non-`time` key of a row is the first thing the
     * statement selected.
     *
     * @return list<stdClass>
     */
    public function rows(): array
    {
        return array_map(
            fn (array $values): stdClass => (object) (array_combine($this->columns, $values) + $this->tags),
            $this->values,
        );
    }
}
