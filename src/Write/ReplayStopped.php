<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Write;

use RuntimeException;

/**
 * Thrown inside a replay, to the batch after one that failed again, so the store leaves
 * that batch and every later one where they are.
 *
 * @internal
 *
 * @see src/docs/writing.md#replaying-failed-batches
 */
class ReplayStopped extends RuntimeException
{
}
