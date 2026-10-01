<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Write;

use OverflowException;

/**
 * A write a BatchingWriter refused, because it would hold more than `maxBuffered` points.
 *
 * Thrown by write() and writeRaw() only when the connection's `overflow` is
 * `refuse`. The points it refuses are not kept; in a list of points, those
 * ahead of them are.
 *
 * @see docs/writing.md#how-much-a-worker-holds
 */
class BufferFullException extends OverflowException
{
    /**
     * @param string $connection the name of the connection that refused them
     * @param int $points the points refused
     * @param int $waiting the points already waiting, buffered or being sent
     * @param int $maxBuffered the most the connection holds
     */
    public function __construct(
        public readonly string $connection,
        public readonly int $points,
        public readonly int $waiting,
        public readonly int $maxBuffered,
    ) {
        parent::__construct(sprintf(
            'InfluxDB connection [%s] refused %d %s: %d are waiting to be sent, and its maxBuffered is %d.',
            $connection,
            $points,
            $points === 1 ? 'point' : 'points',
            $waiting,
            $maxBuffered,
        ));
    }
}
