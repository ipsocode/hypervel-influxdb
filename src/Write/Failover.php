<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Write;

use Closure;
use InfluxDB2\ApiException;
use InfluxDB2\WriteApi;
use InfluxDB2\WriteType;
use Ipsocode\InfluxDB\Write\Concerns\ReportsFailures;
use Throwable;

/**
 * Writes line protocol through the first connection that takes it: a connection, then its fallbacks.
 *
 * Built by the manager for a connection whose `write` block names a `fallback`,
 * and handed to the connection's writer, a FailoverWriter or a BatchingWriter,
 * which gives it its write options. A fallback is written to through a
 * synchronous WriteApi on that connection's client options, built on first use
 * and kept, so no HTTP client is made per write.
 *
 * @see docs/writing.md#falling-back-to-other-connections
 * @see docs/internals.md#falling-back
 */
final class Failover
{
    use ReportsFailures;

    /**
     * The write options the fallbacks are written with: the writer's own.
     *
     * @var null|array<string, mixed>
     */
    private ?array $writeOptions = null;

    /**
     * The WriteApi of each fallback written to so far, by connection name.
     *
     * @var array<string, WriteApi>
     */
    private array $transports = [];

    /**
     * @param string $connection the connection whose writes fall back
     * @param array<string, mixed> $clientOptions that connection's client options, for the bucket and org its writes default to
     * @param FailoverOptions $options which connections to fall back to, and how long a failed one is skipped
     * @param Availability $availability which connections are cooling down, shared with the other writers of the worker
     * @param array<string, array<string, mixed>> $fallbacks the client options of each fallback, by connection name, in the order they are tried
     */
    public function __construct(
        private readonly string $connection,
        private readonly array $clientOptions,
        private readonly FailoverOptions $options,
        private readonly Availability $availability,
        private readonly array $fallbacks,
    ) {
    }

    /**
     * Get a copy that writes to the fallbacks with the given write options, so their retries are the writer's own.
     *
     * @param null|array<string, mixed> $writeOptions
     */
    public function withWriteOptions(?array $writeOptions): static
    {
        $failover = clone $this;

        $failover->writeOptions = $writeOptions;
        $failover->transports = [];

        return $failover;
    }

    /**
     * Write line protocol through the first connection that takes it: the connection itself, then each fallback in order.
     *
     * A connection cooling down is skipped. One that fails to take the write
     * is skipped for `cooldown` seconds and the next is tried; when a later
     * one takes the write, the failures are reported through the exception
     * handler, as a FailoverException, so they are seen. A failure of the
     * connection itself that is the write's own fault, such as a 400 for a
     * line the server cannot parse, is thrown instead: no fallback is tried.
     *
     * A write for the connection's own bucket and org goes to a fallback's own
     * bucket and org; one addressed to another bucket or org keeps them.
     *
     * @param string $payload the line protocol
     * @param Closure(): void $throughConnection posts the write through the connection itself
     * @return string the name of the connection that took the write
     *
     * @throws FailoverException when no connection took the write
     * @throws Throwable the connection's own failure, when it is the write's fault rather than the connection's
     */
    public function write(string $payload, string $precision, string $bucket, string $org, Closure $throughConnection): string
    {
        $attempts = [$this->connection => $throughConnection];

        foreach ($this->fallbacks as $name => $clientOptions) {
            $attempts[$name] = fn () => $this->transport($name)->writeRaw(
                $payload,
                $precision,
                $bucket === $this->clientOptions['bucket'] ? (string) $clientOptions['bucket'] : $bucket,
                $org === $this->clientOptions['org'] ? (string) $clientOptions['org'] : $org,
            );
        }

        $lines = rtrim($payload, "\n");
        $outcomes = [];

        foreach ($attempts as $name => $attempt) {
            $cooldown = $this->availability->unavailableFor($name);

            if ($cooldown !== null) {
                $outcomes[$name] = $cooldown;

                continue;
            }

            $failure = self::attempt($attempt);

            if ($failure === null) {
                if (array_any($outcomes, static fn (float|Throwable $outcome): bool => $outcome instanceof Throwable)) {
                    $this->report(new FailoverException($this->connection, substr_count($lines, "\n") + 1, strlen($lines), $bucket, $outcomes, $name));
                }

                return $name;
            }

            if ($name === $this->connection && ! self::isConnectionFailure($failure)) {
                throw $failure;
            }

            if (self::isConnectionFailure($failure)) {
                $this->availability->markUnavailable($name, $this->options->cooldown);
            }

            $outcomes[$name] = $failure;
        }

        throw new FailoverException($this->connection, substr_count($lines, "\n") + 1, strlen($lines), $bucket, $outcomes);
    }

    /**
     * Determine whether a failure is the connection's rather than the write's own.
     *
     * The client throws an ApiException with no code when the server could
     * not be reached, and with the status when it answered one outside 2xx;
     * it retries those of 429 and above, as a connection's trouble, and
     * refuses the write for the rest, as its own fault.
     */
    public static function isConnectionFailure(Throwable $failure): bool
    {
        return $failure instanceof ApiException && ($failure->getCode() === 0 || $failure->getCode() >= 429);
    }

    /**
     * Get the name of the connection whose writes fall back.
     */
    public function getConnection(): string
    {
        return $this->connection;
    }

    /**
     * Get the names of the fallbacks, in the order they are tried.
     *
     * @return list<string>
     */
    public function getFallbacks(): array
    {
        return array_keys($this->fallbacks);
    }

    /**
     * Get which connections to fall back to, and how long a failed one is skipped.
     */
    public function getOptions(): FailoverOptions
    {
        return $this->options;
    }

    /**
     * Get which connections are cooling down.
     */
    public function getAvailability(): Availability
    {
        return $this->availability;
    }

    /**
     * Get the WriteApi that writes to the given fallback, building it on first use.
     */
    private function transport(string $name): WriteApi
    {
        return $this->transports[$name] ??= new WriteApi(
            $this->fallbacks[$name],
            array_replace($this->writeOptions ?? [], ['writeType' => WriteType::SYNCHRONOUS]),
        );
    }
}
