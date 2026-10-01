<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Write;

/**
 * One request's worth of points: line protocol for one bucket, org and precision.
 *
 * What a BatchingWriter sends, and what it hands to `onFailure` when it
 * cannot: the payload, and where it was going, so it can be written again.
 *
 * @see docs/writing.md#when-a-batch-cannot-be-written
 */
final class Batch
{
    /**
     * @param string $connection the name of the connection that buffered it
     * @param string $bucket the bucket it is written to
     * @param string $org the org it is written to
     * @param string $precision the precision of its timestamps
     * @param string $payload its line protocol, one point per line
     * @param int $points the lines in the payload
     */
    public function __construct(
        public readonly string $connection,
        public readonly string $bucket,
        public readonly string $org,
        public readonly string $precision,
        public readonly string $payload,
        public readonly int $points,
    ) {
    }

    /**
     * Get the size of the payload in bytes.
     */
    public function bytes(): int
    {
        return strlen($this->payload);
    }
}
