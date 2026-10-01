<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\InfluxQL;

use RuntimeException;
use Throwable;

/**
 * A statement InfluxDB refused, or one that never reached it.
 *
 * The InfluxQL twin of Hypervel\Database\QueryException: the message carries
 * the connection name and the statement exactly as it was sent, bindings
 * already embedded, so a log line is enough to reproduce the failure.
 */
class QueryException extends RuntimeException
{
    /**
     * The server's error message, or the transport exception's, without the context the message adds.
     */
    protected string $error;

    /**
     * @param string $sql the statement as sent, with the bindings embedded
     * @param list<mixed> $bindings the bindings before they were embedded
     * @param string|Throwable $error the server's error message, or the transport exception
     */
    public function __construct(
        protected string $connectionName,
        protected string $sql,
        protected array $bindings,
        string|Throwable $error,
    ) {
        $previous = $error instanceof Throwable ? $error : null;

        $message = $error instanceof Throwable ? $error->getMessage() : $error;

        $this->error = $message;

        parent::__construct(
            $message . ' (Connection: ' . $connectionName . ', InfluxQL: ' . $sql . ')',
            $previous instanceof Throwable ? (int) $previous->getCode() : 0,
            $previous,
        );
    }

    /**
     * Get the name of the connection the statement ran on.
     */
    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    /**
     * Get the server's error message, or the transport exception's, without the connection and statement.
     *
     * The `influxql` database driver rethrows this rather than the whole
     * exception, since Hypervel's QueryException adds the same context again.
     */
    public function getError(): string
    {
        return $this->error;
    }

    /**
     * Get the statement as it was sent, with the bindings embedded.
     */
    public function getSql(): string
    {
        return $this->sql;
    }

    /**
     * Get the bindings before they were embedded.
     *
     * @return list<mixed>
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }
}
