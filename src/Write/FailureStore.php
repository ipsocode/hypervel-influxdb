<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Write;

/**
 * Where a BatchingWriter keeps a batch that could not be written.
 *
 * A connection names its store as `write.onFailure`, by driver name or as a
 * driver array, and a store is resolved once per connection. It runs in the
 * coroutine that sent the batch, so it holds no request state.
 *
 * @see src/docs/writing.md#when-a-batch-cannot-be-written
 */
interface FailureStore
{
    /**
     * Keep a batch that failed after its retries.
     */
    public function store(FailedBatch $batch): void;
}
