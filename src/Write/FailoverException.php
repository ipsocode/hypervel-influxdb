<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Write;

use RuntimeException;
use Throwable;

/**
 * A write a connection failed to take, and what became of it: a fallback took it, or none did.
 *
 * When a fallback took the write, a writer reports it through the application's
 * exception handler, so the connection's failure is seen. When none did, the
 * writer throws it, or, for a batch, wraps it in the BatchWriteException. Its
 * previous exception is the connection's own failure, when the connection was
 * tried rather than skipped.
 *
 * @see docs/writing.md#falling-back-to-other-connections
 */
class FailoverException extends RuntimeException
{
    /**
     * @param string $connection the connection the write was for
     * @param int $points the lines of line protocol
     * @param int $bytes the bytes of line protocol
     * @param string $bucket the bucket the write was for
     * @param array<string, float|Throwable> $outcomes what each connection did, by name, in the order they were tried: the failure of one that was tried, or the seconds left of the cooldown of one that was skipped
     * @param null|string $takenBy the connection that took the write, or null when none did
     */
    public function __construct(
        public readonly string $connection,
        public readonly int $points,
        public readonly int $bytes,
        public readonly string $bucket,
        public readonly array $outcomes,
        public readonly ?string $takenBy = null,
    ) {
        $failure = $outcomes[$connection] ?? null;

        parent::__construct(sprintf(
            'InfluxDB connection [%s] failed to write %d %s (%d bytes) for bucket [%s], %s: %s.',
            $connection,
            $points,
            $points === 1 ? 'point' : 'points',
            $bytes,
            $bucket,
            $takenBy === null ? 'and none of its fallbacks took them' : sprintf('which connection [%s] took', $takenBy),
            $this->reasons(),
        ), 0, $failure instanceof Throwable ? $failure : null);
    }

    /**
     * Describe what each connection did, in the order they were tried.
     *
     * Such as `[main] [503] Error connecting to the API (…); [backup] skipped for another 12.3 seconds`.
     */
    public function reasons(): string
    {
        $reasons = [];

        foreach ($this->outcomes as $name => $outcome) {
            $reasons[] = $outcome instanceof Throwable
                ? sprintf('[%s] %s', $name, rtrim($outcome->getMessage(), '.'))
                : sprintf('[%s] skipped for another %.1f seconds', $name, $outcome);
        }

        return implode('; ', $reasons);
    }

    /**
     * Get the failure of each connection that was tried, by name.
     *
     * @return array<string, Throwable>
     */
    public function failures(): array
    {
        return array_filter($this->outcomes, static fn (float|Throwable $outcome): bool => $outcome instanceof Throwable);
    }

    /**
     * Get the seconds left of the cooldown of each connection that was skipped, by name.
     *
     * @return array<string, float>
     */
    public function skipped(): array
    {
        return array_filter($this->outcomes, static fn (float|Throwable $outcome): bool => is_float($outcome));
    }
}
