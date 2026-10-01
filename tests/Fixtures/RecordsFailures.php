<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Fixtures;

use Ipsocode\InfluxDB\Write\Batch;
use Ipsocode\InfluxDB\Write\BatchWriteException;

/**
 * An invokable `onFailure`, standing in for the spill an application writes failed batches to.
 */
final class RecordsFailures
{
    /**
     * The batches it was handed, oldest first.
     *
     * @var list<Batch>
     */
    public array $batches = [];

    public function __invoke(Batch $batch, BatchWriteException $exception): void
    {
        $this->batches[] = $batch;
    }
}
