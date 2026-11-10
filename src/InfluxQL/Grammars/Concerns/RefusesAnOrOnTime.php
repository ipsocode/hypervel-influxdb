<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\InfluxQL\Grammars\Concerns;

use RuntimeException;

/**
 * Refuses a WHERE clause that joins a comparison on `time` with another by OR.
 *
 * InfluxDB answers an OR between time ranges with no points and no error, so the
 * statement is refused as it compiles. Shared by both InfluxQL grammars, so the
 * refusal reads the same whichever one compiles the statement.
 *
 * @see src/docs/influxql.md#when-a-statement-fails
 */
trait RefusesAnOrOnTime
{
    /**
     * Refuse the where clauses unless none of them combines a comparison on `time` with OR.
     *
     * @param array<int, array<string, mixed>> $wheres the clauses as the builders keep them, whose type is written 'In' or 'in' alike
     *
     * @throws RuntimeException
     */
    protected function ensureNoOrOnTime(array $wheres): void
    {
        foreach ($wheres as $index => $where) {
            $orsTime = (strtolower($where['type']) === 'in' && count($where['values']) > 1 && $this->comparesTime($where))
                || (strtolower($where['type']) === 'between' && $where['not'] && $this->comparesTime($where))
                || ($index > 0 && strtolower($where['boolean']) === 'or' && ($this->comparesTime($where) || $this->comparesTime($wheres[$index - 1])));

            if ($orsTime) {
                throw new RuntimeException('InfluxDB answers an OR between time ranges with no points; query each time range on its own and merge the results.');
            }
        }
    }

    /**
     * Determine whether a where clause, or a group nested in it, compares the `time` column.
     *
     * @param array<string, mixed> $where
     */
    private function comparesTime(array $where): bool
    {
        if (strtolower($where['type']) === 'nested') {
            return array_any($where['query']->wheres, fn (array $inner): bool => $this->comparesTime($inner));
        }

        return ($where['column'] ?? $where['first'] ?? null) === 'time';
    }
}
