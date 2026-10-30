<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Fixtures;

use Ipsocode\InfluxDB\Write\FailedBatch;
use Ipsocode\InfluxDB\Write\ReplayableFailureStore;
use RuntimeException;

/**
 * A ReplayableFailureStore that keeps its batches in an array, oldest first.
 */
class InMemoryFailureStore implements ReplayableFailureStore
{
    /**
     * Whether replay() throws before it hands over a batch.
     */
    public bool $broken = false;

    /**
     * @param list<FailedBatch> $batches
     */
    public function __construct(public array $batches = [])
    {
    }

    public function store(FailedBatch $batch): void
    {
        $this->batches[] = $batch;
    }

    public function replay(callable $writer, int $limit = 100): int
    {
        if ($this->broken) {
            throw new RuntimeException('The store is down.');
        }

        $handed = min($limit, count($this->batches));

        for ($i = 0; $i < $handed; ++$i) {
            $writer($this->batches[0]);

            array_shift($this->batches);
        }

        return $handed;
    }
}
