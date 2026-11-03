<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Write\Concerns;

use InvalidArgumentException;

/**
 * Describes a write option of a connection that is out of range or of the wrong kind.
 */
trait DescribesInvalidOptions
{
    /**
     * Describe an option that is out of range or of the wrong type.
     */
    private static function invalid(string $connection, string $key, mixed $value, string $expected): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'InfluxDB connection [%s] has an invalid write.%s [%s]; expected %s.',
            $connection,
            $key,
            match (true) {
                is_string($value) => $value,
                is_scalar($value) => var_export($value, true),
                default => get_debug_type($value),
            },
            $expected,
        ));
    }
}
