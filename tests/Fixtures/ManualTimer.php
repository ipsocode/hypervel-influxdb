<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Fixtures;

use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\Timer;

/**
 * A timer whose callbacks run only when a test fires them, so no test waits out an interval.
 */
class ManualTimer extends Timer
{
    /**
     * The armed callbacks and their timeouts, by timer id.
     *
     * @var array<int, array{float, callable}>
     */
    public array $armed = [];

    private int $nextId = 1;

    public function after(float $timeout, callable $closure, string $identifier = Constants::WORKER_EXIT): int
    {
        $this->armed[$this->nextId] = [$timeout, $closure];

        return $this->nextId++;
    }

    public function clear(int $id): void
    {
        unset($this->armed[$id]);
    }

    /**
     * Get the timeouts of the armed callbacks, in the order they were armed.
     *
     * @return list<float>
     */
    public function timeouts(): array
    {
        return array_values(array_map(static fn (array $armed): float => $armed[0], $this->armed));
    }

    /**
     * Run the armed callbacks, as their timeouts passing would.
     */
    public function fire(): void
    {
        $armed = $this->armed;
        $this->armed = [];

        foreach ($armed as [, $closure]) {
            $closure();
        }
    }
}
