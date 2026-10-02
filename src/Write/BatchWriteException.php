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
 * and hands it to `onFailure` with the batch. The failure itself is its
 * previous: usually an InfluxDB2\ApiException after the client's retries, or,
 * on a connection with fallbacks, the FailoverException naming what each
 * connection did.
 *
 * @see docs/writing.md#when-a-batch-cannot-be-written
 * @see docs/writing.md#falling-back-to-other-connections
 */
class BatchWriteException extends RuntimeException
{
    public function __construct(
        public readonly Batch $batch,
        Throwable $previous,
    ) {
        parent::__construct(sprintf(
            'InfluxDB connection [%s] dropped a batch of %d %s (%d bytes) for bucket [%s]%s',
            $batch->connection,
            $batch->points,
            $batch->points === 1 ? 'point' : 'points',
            $batch->bytes(),
            $batch->bucket,
            $previous instanceof FailoverException
                ? sprintf(', which none of its fallbacks took: %s.', $previous->reasons())
                : ': ' . $previous->getMessage(),
        ), 0, $previous);
    }
}
