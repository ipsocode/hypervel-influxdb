<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\InfluxQL;

use stdClass;

/**
 * The result of one statement in a /query response.
 *
 * A request may carry several `;`-separated statements; InfluxDB answers with
 * one of these per statement, in order. A statement that failed carries its
 * error message here instead of series — the HTTP status is still 200.
 */
final class Result
{
    /**
     * @param list<Series> $series
     * @param null|string $error the server's message when the statement failed
     * @param bool $partial whether the server truncated the result (`max-row-limit`)
     */
    public function __construct(
        public readonly int $statementId,
        public readonly array $series,
        public readonly ?string $error = null,
        public readonly bool $partial = false,
    ) {
    }

    /**
     * Build a result from one decoded `results` entry of a /query response.
     *
     * @param array<string, mixed> $result
     */
    public static function fromArray(array $result): self
    {
        return new self(
            (int) ($result['statement_id'] ?? 0),
            array_map(Series::fromArray(...), array_values((array) ($result['series'] ?? []))),
            isset($result['error']) ? (string) $result['error'] : null,
            (bool) ($result['partial'] ?? false),
        );
    }

    /**
     * Get the rows of every series, flattened in series order.
     *
     * @return list<stdClass>
     */
    public function rows(): array
    {
        return array_merge(...array_map(fn (Series $series): array => $series->rows(), $this->series));
    }
}
