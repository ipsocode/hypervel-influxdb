<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Write;

use RuntimeException;
use Throwable;

/**
 * A batch that could not be written, and was dropped.
 *
 * A BatchingWriter reports it through the application's exception handler
 * instead of throwing it at whichever write() happened to fill the batch,
 * and hands it to `onFailure` with the batch. The failure itself, usually an
 * InfluxDB2\ApiException after the client's retries, is its previous.
 *
 * @see docs/writing.md#when-a-batch-cannot-be-written
 */
class BatchWriteException extends RuntimeException
{
    public function __construct(
        public readonly Batch $batch,
        Throwable $previous,
    ) {
        parent::__construct(sprintf(
            'InfluxDB connection [%s] dropped a batch of %d %s (%d bytes) for bucket [%s]: %s',
            $batch->connection,
            $batch->points,
            $batch->points === 1 ? 'point' : 'points',
            $batch->bytes(),
            $batch->bucket,
            $previous->getMessage(),
        ), 0, $previous);
    }
}
