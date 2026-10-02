<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Write;

/**
 * Which connections are skipped for a while, after they failed.
 *
 * One per worker, kept by the manager and shared by every writer that falls
 * back to other connections, so a connection one writer finds down is skipped
 * by the others too. Cooldowns are kept in hrtime() nanoseconds, so a change
 * of the clock neither shortens nor lengthens one.
 *
 * @see docs/writing.md#falling-back-to-other-connections
 */
final class Availability
{
    /**
     * When each connection's cooldown ends, in hrtime() nanoseconds, by connection name.
     *
     * @var array<string, int>
     */
    private array $unavailableUntil = [];

    /**
     * Determine whether the given connection is to be tried.
     */
    public function isAvailable(string $connection): bool
    {
        return $this->unavailableFor($connection) === null;
    }

    /**
     * Get the seconds left of the given connection's cooldown, or null when it is available.
     */
    public function unavailableFor(string $connection): ?float
    {
        $until = $this->unavailableUntil[$connection] ?? null;

        if ($until === null) {
            return null;
        }

        $left = $until - hrtime(true);

        if ($left <= 0) {
            unset($this->unavailableUntil[$connection]);

            return null;
        }

        return $left / 1_000_000_000;
    }

    /**
     * Skip the given connection for the given seconds from now.
     *
     * A cooldown already running is replaced rather than extended, and zero
     * seconds or less makes the connection available.
     */
    public function markUnavailable(string $connection, float $seconds): void
    {
        if ($seconds <= 0) {
            $this->markAvailable($connection);

            return;
        }

        $this->unavailableUntil[$connection] = hrtime(true) + (int) round($seconds * 1_000_000_000);
    }

    /**
     * End the given connection's cooldown, if one is running.
     */
    public function markAvailable(string $connection): void
    {
        unset($this->unavailableUntil[$connection]);
    }

    /**
     * Get the connections cooling down, with the seconds left of each.
     *
     * @return array<string, float>
     */
    public function unavailable(): array
    {
        $left = [];

        foreach (array_keys($this->unavailableUntil) as $connection) {
            $seconds = $this->unavailableFor($connection);

            if ($seconds !== null) {
                $left[$connection] = $seconds;
            }
        }

        return $left;
    }
}
