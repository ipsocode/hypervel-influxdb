<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\InfluxQL\Driver;

use Closure;
use DateTimeInterface;
use Generator;
use Hypervel\Contracts\Database\Query\Expression as ExpressionContract;
use Hypervel\Database\Connection as DatabaseConnection;
use InfluxDB2\ApiException;
use InvalidArgumentException;
use Ipsocode\InfluxDB\InfluxDBManager;
use Ipsocode\InfluxDB\InfluxQL\Connection as InfluxQLConnection;
use Ipsocode\InfluxDB\InfluxQL\Dialect;
use Ipsocode\InfluxDB\InfluxQL\QueryException as InfluxQLQueryException;
use Ipsocode\InfluxDB\InfluxQL\Series;
use Ipsocode\InfluxDB\InfluxQL\Version;
use LogicException;
use RuntimeException;
use stdClass;
use Swoole\Coroutine\CanceledException;
use Throwable;

/**
 * A Hypervel database connection that runs InfluxQL on InfluxDB 1.x, 2.x and 3.
 *
 * The `influxql` database driver: statements compile for the InfluxDB
 * connection's `version` and are sent, values embedded, only to a server that
 * runs it; inserts, updates and transactions are refused. Hypervel's database
 * pool hands each connection, with its own HTTP transport, to one coroutine at a time.
 *
 * @see docs/influxql-driver.md#what-to-expect
 * @see docs/internals.md#the-database-drivers
 */
class Connection extends DatabaseConnection
{
    /**
     * Create a new InfluxQL database connection instance.
     *
     * @param null|InfluxQLConnection $influxql the connection the statements are sent through, or none until the connection reconnects
     * @param Version $version the InfluxDB version the statements compile for, which the server has to run
     * @param null|string $retentionPolicy the retention policy the statements are addressed to, which decides whether InfluxDB 2.x runs a DELETE
     * @param string $database the database the statements are addressed to
     * @param array<string, mixed> $config the database connection's config
     */
    public function __construct(
        protected ?InfluxQLConnection $influxql,
        protected Version $version = Version::V1,
        protected ?string $retentionPolicy = null,
        string $database = '',
        array $config = [],
    ) {
        parent::__construct($database, '', $config);
    }

    /**
     * Make a connection from a `config/database.php` connection with the `influxql` driver.
     *
     * `connection` names the InfluxDB connection, the default one when left
     * out; its config decides the rest, and replaces a `database` given here.
     *
     * @param array<string, mixed> $config the database connection's config, as Hypervel's connection factory parses it
     *
     * @throws InvalidArgumentException when the InfluxDB connection is not configured, or is misconfigured for its version
     */
    public static function fromConfig(InfluxDBManager $influxdb, array $config): self
    {
        $name = (string) ($config['connection'] ?? $influxdb->getDefaultConnection());

        $options = $influxdb->getConnectionConfig($name);

        $influxql = InfluxQLConnection::make($influxdb->connection($name), $options)
            ->setServerVersion($influxdb->getDetectedServerVersion($name));

        $url = (string) $influxql->getClient()->options['url'];

        $config = [
            'connection' => $name,
            'database' => $influxql->getDatabase(),
            'host' => parse_url($url, PHP_URL_HOST) ?: null,
            'port' => parse_url($url, PHP_URL_PORT) ?: null,
        ] + $config;

        return new self($influxql, $influxql->getVersion(), $influxql->getRetentionPolicy(), (string) $influxql->getDatabase(), $config);
    }

    /**
     * Get a new query builder instance.
     */
    public function query(): Builder
    {
        return new Builder($this, $this->getQueryGrammar(), $this->getPostProcessor());
    }

    /**
     * Run a select statement against the database.
     *
     * The first result's series are flattened into rows, each with its series'
     * GROUP BY tags appended; series() keeps them apart.
     * `$useReadPdo` and `$fetchUsing` configure PDO, which the connection does not use.
     *
     * @return list<stdClass>
     */
    public function select(string $query, array $bindings = [], bool $useReadPdo = true, array $fetchUsing = []): array
    {
        return $this->runInfluxQL($query, $bindings, [], static fn (InfluxQLConnection $influxql, string $statement): array => $influxql->select($statement));
    }

    /**
     * Run a select statement against the database and return a generator.
     *
     * The server answers with the whole result at once, so this yields the rows select() reads.
     *
     * @return Generator<int, stdClass>
     */
    public function cursor(string $query, array $bindings = [], bool $useReadPdo = true, array $fetchUsing = []): Generator
    {
        yield from $this->select($query, $bindings, $useReadPdo, $fetchUsing);
    }

    /**
     * Run a select statement and return the series of its first result, as InfluxDB grouped them.
     *
     * @param list<mixed> $bindings
     * @return list<Series>
     */
    public function series(string $query, array $bindings = []): array
    {
        return $this->runInfluxQL($query, $bindings, [], static function (InfluxQLConnection $influxql, string $statement): array {
            $results = $influxql->results($statement);

            return $results === [] ? [] : $results[0]->series;
        });
    }

    /**
     * Execute a statement whose result is not needed, such as `DELETE` or `SELECT ... INTO`.
     */
    public function statement(string $query, array $bindings = []): bool
    {
        return $this->runInfluxQL($query, $bindings, true, static fn (InfluxQLConnection $influxql, string $statement): bool => $influxql->statement($statement));
    }

    /**
     * Run a statement that could affect points, and return 0: InfluxQL reports no count.
     */
    public function affectingStatement(string $query, array $bindings = []): int
    {
        $this->statement($query, $bindings);

        return 0;
    }

    /**
     * Run a raw statement, as statement() runs one.
     */
    public function unprepared(string $query): bool
    {
        return $this->statement($query);
    }

    /**
     * Refuse an insert statement: InfluxQL has none.
     *
     * @throws LogicException
     */
    public function insert(string $query, array $bindings = []): bool
    {
        $this->refuseWrites();
    }

    /**
     * Refuse an update statement: InfluxQL has none.
     *
     * @throws LogicException
     */
    public function update(string $query, array $bindings = []): int
    {
        $this->refuseWrites();
    }

    /**
     * Prepare the query bindings for execution.
     *
     * A boolean stays a boolean, where Hypervel casts it to 0 or 1 for PDO:
     * InfluxQL compares a boolean field with `true` or `false` only.
     */
    public function prepareBindings(array $bindings): array
    {
        foreach ($bindings as $key => $value) {
            if ($value instanceof DateTimeInterface) {
                $bindings[$key] = Dialect::formatDateTime($value);
            }
        }

        return $bindings;
    }

    /**
     * Escape a value for safe InfluxQL embedding, as the grammar embeds one.
     *
     * @throws InvalidArgumentException for a value InfluxQL has no literal for, such as null
     * @throws RuntimeException for a binary value
     */
    public function escape(mixed $value, bool $binary = false): string
    {
        if ($binary) {
            throw new RuntimeException('InfluxQL has no binary literal.');
        }

        if ($value instanceof ExpressionContract) {
            return (string) $this->getQueryGrammar()->getValue($value);
        }

        return is_string($value) ? $this->escapeString($value) : Dialect::escape($value);
    }

    /**
     * Determine whether the connection is responsive.
     *
     * A connection that has let go of its driver resources has nothing open
     * to check, and counts as responsive until it reconnects.
     *
     * @internal
     */
    public function ping(): bool
    {
        if ($this->influxql === null) {
            return true;
        }

        try {
            $this->influxql->getQueryApi()->ping();

            return true;
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Get the version of InfluxDB the server reports, such as `1.8.10` or `v2.7.12`.
     *
     * Read from `/ping` once and kept, unless the server named it when the
     * Hypervel server started; empty when the server names none.
     *
     * @throws ApiException when the server cannot be reached, or answers with an error
     */
    public function getServerVersion(): string
    {
        return $this->getInfluxQL()->getServerVersion() ?? '';
    }

    /**
     * Get the InfluxDB version the statements compile for, which the server has to run.
     */
    public function getVersion(): Version
    {
        return $this->version;
    }

    /**
     * Get the InfluxQL connection the statements are sent through, reconnecting first if the connection has let go of it.
     */
    public function getInfluxQL(): InfluxQLConnection
    {
        $this->reconnectIfMissingConnection();

        /** @var InfluxQLConnection */
        return $this->influxql;
    }

    /**
     * Determine whether the connection has an active physical transaction.
     *
     * InfluxDB has no transactions, so there never is one.
     */
    public function inTransaction(): bool
    {
        return false;
    }

    /**
     * Get the default query grammar instance, for the connection's version and retention policy.
     */
    protected function getDefaultQueryGrammar(): Grammar
    {
        return new Grammar($this, $this->version, $this->retentionPolicy);
    }

    /**
     * Get the default database driver name.
     */
    protected function getDefaultDriverName(): string
    {
        return 'influxql';
    }

    /**
     * Escape a string value for safe InfluxQL embedding.
     */
    protected function escapeString(string $value): string
    {
        return Dialect::quoteString($value);
    }

    /**
     * Determine whether the connection has driver resources.
     */
    protected function hasDriverResources(): bool
    {
        return $this->influxql !== null;
    }

    /**
     * Disconnect the driver resources.
     *
     * There is no open session to end: the driver resources and their HTTP client are let go of.
     */
    protected function disconnectDriverResources(): void
    {
        $this->forgetDriverResources();
    }

    /**
     * Forget the driver resources without performing physical cleanup.
     */
    protected function forgetDriverResources(): void
    {
        $this->influxql = null;
    }

    /**
     * Refresh the driver resources from a fresh connection.
     *
     * The version and retention policy come too, so the grammar is made again for them.
     */
    protected function replaceDriverResources(DatabaseConnection $fresh): void
    {
        /** @var self $fresh */
        $influxql = $fresh->influxql;
        $version = $fresh->version;
        $retentionPolicy = $fresh->retentionPolicy;
        $database = $fresh->database;
        $configuredDatabase = $fresh->configuredDatabase;
        $tablePrefix = $fresh->tablePrefix;
        $configuredTablePrefix = $fresh->configuredTablePrefix;
        $config = $fresh->config;

        try {
            $this->disconnect();
        } finally {
            $this->influxql = $influxql;
            $this->version = $version;
            $this->retentionPolicy = $retentionPolicy;
            $this->database = $database;
            $this->configuredDatabase = $configuredDatabase;
            $this->tablePrefix = $tablePrefix;
            $this->configuredTablePrefix = $configuredTablePrefix;
            $this->config = $config;
            $this->latestReadWriteTypeRetrieved = null;
            $this->useDefaultQueryGrammar();
        }
    }

    /**
     * Run a statement on the InfluxQL connection, through Hypervel's run(), with its values embedded.
     *
     * The values are embedded first, so one InfluxQL has no literal for fails
     * before anything, a /ping included, is sent. The package's QueryException
     * is unwrapped: run() wraps it in Hypervel's, which adds the same connection and statement.
     *
     * @template TResult
     *
     * @param array<mixed> $bindings
     * @param TResult $pretendResult
     * @param Closure(InfluxQLConnection, string): TResult $send
     * @return TResult
     */
    private function runInfluxQL(string $query, array $bindings, mixed $pretendResult, Closure $send): mixed
    {
        return $this->run($query, $bindings, function (string $query, array $bindings) use ($pretendResult, $send): mixed {
            if ($this->pretending()) {
                return $pretendResult;
            }

            $statement = $this->getQueryGrammar()->substituteBindingsIntoRawSql($query, $this->prepareBindings($bindings));

            $influxql = $this->getInfluxQL();

            $this->ensureServerRunsTheVersion($influxql);

            try {
                return $send($influxql, $statement);
            } catch (InfluxQLQueryException $exception) {
                throw $exception->getPrevious() ?? new RuntimeException($exception->getError());
            }
        });
    }

    /**
     * Refuse to send a statement unless the server runs the major version the connection compiles for.
     *
     * The InfluxQL connection keeps the version it reads, so this costs at
     * most one /ping per pooled connection.
     *
     * @throws ApiException when the server cannot be reached, or answers /ping with an error
     * @throws RuntimeException when it runs another major version, or names none
     */
    private function ensureServerRunsTheVersion(InfluxQLConnection $influxql): void
    {
        $version = $influxql->getServerVersion();

        if ($version === null || ! $this->version->matches($version)) {
            throw new RuntimeException(sprintf(
                'The influxql driver compiles for %s on this connection (version %s), but the server reports %s.',
                $this->version->label(),
                $this->version->value,
                $version === null ? 'no version' : "version [{$version}]",
            ));
        }
    }

    /**
     * Refuse a statement that would write points, before anything is sent.
     *
     * @throws LogicException
     */
    private function refuseWrites(): never
    {
        throw new LogicException('InfluxQL has no INSERT or UPDATE; write points through InfluxDB::writeApi() instead.');
    }
}
