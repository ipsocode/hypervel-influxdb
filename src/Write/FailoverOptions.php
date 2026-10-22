<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Write;

use InvalidArgumentException;

/**
 * Which connections a write falls back to when its own cannot take it, and
 * how long a connection that failed is skipped.
 *
 * @see docs/writing.md#falling-back-to-other-connections
 */
final class FailoverOptions
{
    /**
     * The seconds a connection that failed is skipped when `cooldown` is not set.
     */
    public const float DEFAULT_COOLDOWN = 30.0;

    /**
     * @param list<string> $fallbacks the connections to fall back to, in order
     * @param float $cooldown the seconds a connection is skipped after it fails
     */
    public function __construct(
        public readonly array $fallbacks,
        public readonly float $cooldown = self::DEFAULT_COOLDOWN,
    ) {
    }

    /**
     * Resolve the failover options of a connection's write options, or null when it names no fallback.
     *
     * `fallback` is a connection name, a list of them, or the comma-separated
     * names env() reads; `cooldown` a number of seconds, zero included, which
     * may be the numeric string env() reads. A key left out or null takes its
     * default.
     *
     * @param array<string, mixed> $writeOptions the connection's `write` block
     * @param string $connection the connection's name, for the message
     *
     * @throws InvalidArgumentException when an option is of the wrong kind, or the connection falls back to itself
     */
    public static function fromConfig(array $writeOptions, string $connection): ?self
    {
        $fallbacks = self::fallbacks($writeOptions['fallback'] ?? null, $connection);

        if ($fallbacks === []) {
            return null;
        }

        return new self($fallbacks, self::cooldown($writeOptions['cooldown'] ?? null, $connection));
    }

    /**
     * Read the fallback connections: a name, a list of names or comma-separated names, each once.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    private static function fallbacks(mixed $value, string $connection): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value) || ! array_is_list($value) || ! array_all($value, static fn (mixed $name): bool => is_string($name))) {
            throw self::invalid($connection, 'fallback', $value, 'a connection name or a list of them');
        }

        /** @var list<string> $names */
        $names = array_values(array_unique(array_filter(array_map(trim(...), $value), static fn (string $name): bool => $name !== '')));

        if (in_array($connection, $names, true)) {
            throw self::invalid($connection, 'fallback', $connection, 'the name of another connection');
        }

        return $names;
    }

    /**
     * Read the cooldown: a number of seconds, zero or above.
     *
     * @throws InvalidArgumentException
     */
    private static function cooldown(mixed $value, string $connection): float
    {
        if ($value === null) {
            return self::DEFAULT_COOLDOWN;
        }

        if (is_numeric($value) && (float) $value >= 0) {
            return (float) $value;
        }

        throw self::invalid($connection, 'cooldown', $value, 'a number of seconds, zero or above');
    }

    /**
     * Describe an option that is of the wrong kind.
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
