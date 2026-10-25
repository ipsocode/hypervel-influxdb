<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Jobs;

use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Queue\InteractsWithQueue;
use Ipsocode\InfluxDB\InfluxDBManager;
use Ipsocode\InfluxDB\Write\FailedBatch;

/**
 * Writes a batch the `queue` driver kept, and throws when the write fails, so the queue retries it.
 *
 * @see src/docs/writing.md#the-queue-driver
 */
class ReplayFailedBatch implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public readonly FailedBatch $batch)
    {
    }

    /**
     * Write the batch through its connection's synchronous WriteApi.
     */
    public function handle(InfluxDBManager $manager): void
    {
        $manager->writeFailed($this->batch);
    }
}
