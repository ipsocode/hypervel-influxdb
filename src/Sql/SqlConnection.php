<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Sql;

use DateTimeInterface;
use Generator;
use Hypervel\Database\Connection;
use InfluxDB2\ApiException;
use InvalidArgumentException;
use Ipsocode\InfluxDB\InfluxDBManager;
use Ipsocode\InfluxDB\InfluxQL\Version;
use LogicException;
use stdClass;
use Swoole\Coroutine\CanceledException;
use Throwable;

/**
 * A read-only Hypervel database connection that runs SQL on InfluxDB 3.
 *
 * The `influxdb` database driver: each statement is one POST to
 * `/api/v3/query_sql` with its values embedded; writes and transactions are
 * refused. Hypervel's database pool hands each connection, with its own HTTP
 * transport, to one coroutine at a time.
 *
 * @see docs/sql.md#what-to-expect
 * @see docs/internals.md#the-database-drivers
 */
class SqlConnection extends Connection
{
    /**
     * Create a new InfluxDB 3 SQL connection instance.
     *
     * @param null|SqlApi $api the transport the statements are sent through, or none until the connection reconnects
     * @param string $database the InfluxDB 3 database the statements run against
     * @param array<string, mixed> $config the database connection's config
     */
    public function __construct(
        protected ?SqlApi $api,
        string $database = '',
        string $tablePrefix = '',
        array $config = [],
    ) {
        parent::__construct($database, $tablePrefix, $config);
    }

    /**
     * Make a connection from a `config/database.php` connection with the `influxdb` driver.
     *
     * `connection` names the InfluxDB connection, the default one when left
     * out, and `database` the database, that connection's bucket when left out.
     *
     * @param array<string, mixed> $config the database connection's config, as Hypervel's connection factory parses it
     *
     * @throws InvalidArgumentException when the InfluxDB connection is not configured, or is not an InfluxDB 3 connection
     */
    public static function fromConfig(InfluxDBManager $influxdb, array $config): self
    {
        $name = (string) ($config['connection'] ?? $influxdb->getDefaultConnection());

        $options = $influxdb->getConnectionConfig($name);

        $version = Version::resolve($options['version'] ?? null, $name);

        if ($version !== Version::V3) {
            throw new InvalidArgumentException(sprintf(
                'Database connection [%s] uses InfluxDB connection [%s], which is version %s; SQL needs an InfluxDB 3 connection (version v3).',
                $config['name'] ?? '',
                $name,
                $version->value,
            ));
        }

        $client = $influxdb->connection($name);

        $database = is_string($config['database'] ?? null) && $config['database'] !== ''
            ? $config['database']
            : (string) $client->options['bucket'];

        $config = [
            'connection' => $name,
            'database' => $database,
            'host' => parse_url((string) $client->options['url'], PHP_URL_HOST) ?: null,
            'port' => parse_url((string) $client->options['url'], PHP_URL_PORT) ?: null,
        ] + $config;

        return new self(new SqlApi($client->options), $database, (string) ($config['prefix'] ?? ''), $config);
    }

    /**
     * Run a select statement against the database.
     *
     * `$useReadPdo` and `$fetchUsing` configure PDO, which the connection does not use.
     *
     * @return list<stdClass>
     */
    public function select(string $query, array $bindings = [], bool $useReadPdo = true, array $fetchUsing = []): array
    {
        return $this->run($query, $bindings, function (string $query, array $bindings): array {
            if ($this->pretending()) {
                return [];
            }

            return $this->getApi()->query(
                $this->getDatabaseName(),
                $this->getQueryGrammar()->substituteBindingsIntoRawSql($query, $this->prepareBindings($bindings)),
            );
        });
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
     * Refuse a statement, which could only write to InfluxDB 3.
     *
     * @throws LogicException
     */
    public function statement(string $query, array $bindings = []): bool
    {
        $this->refuseWrites();
    }

    /**
     * Refuse an affecting statement, which could only write to InfluxDB 3.
     *
     * @throws LogicException
     */
    public function affectingStatement(string $query, array $bindings = []): int
    {
        $this->refuseWrites();
    }

    /**
     * Refuse a raw statement, which could only write to InfluxDB 3.
     *
     * @throws LogicException
     */
    public function unprepared(string $query): bool
    {
        $this->refuseWrites();
    }

    /**
     * Prepare the query bindings for execution.
     *
     * A boolean stays a boolean, where Hypervel casts it to 0 or 1 for PDO:
     * DataFusion does not compare a boolean column with a number.
     */
    public function prepareBindings(array $bindings): array
    {
        $grammar = $this->getQueryGrammar();

        foreach ($bindings as $key => $value) {
            if ($value instanceof DateTimeInterface) {
                $bindings[$key] = $value->format($grammar->getDateFormat());
            }
        }

        return $bindings;
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
        if ($this->api === null) {
            return true;
        }

        try {
            $this->api->ping();

            return true;
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Get the version of InfluxDB the server runs, such as `3.11.5`, from `/ping`.
     *
     * @throws ApiException when the server cannot be reached, or does not answer as InfluxDB 3 does
     */
    public function getServerVersion(): string
    {
        $version = $this->getApi()->ping()['version'] ?? '';

        return is_string($version) ? $version : '';
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
     * Get the transport the statements are sent through, reconnecting first if the connection has let go of it.
     */
    public function getApi(): SqlApi
    {
        $this->reconnectIfMissingConnection();

        /** @var SqlApi */
        return $this->api;
    }

    /**
     * Get the default query grammar instance.
     */
    protected function getDefaultQueryGrammar(): SqlGrammar
    {
        return new SqlGrammar($this);
    }

    /**
     * Get the default database driver name.
     */
    protected function getDefaultDriverName(): string
    {
        return 'influxdb';
    }

    /**
     * Escape a string value for safe SQL embedding.
     *
     * DataFusion reads a doubled `'` as a string literal's only escape, and a backslash as itself.
     */
    protected function escapeString(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * Escape a boolean value for safe SQL embedding.
     */
    protected function escapeBool(bool $value): string
    {
        return $value ? 'TRUE' : 'FALSE';
    }

    /**
     * Determine whether the connection has driver resources.
     */
    protected function hasDriverResources(): bool
    {
        return $this->api !== null;
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
        $this->api = null;
    }

    /**
     * Refresh the driver resources from a fresh connection.
     */
    protected function replaceDriverResources(Connection $fresh): void
    {
        /** @var self $fresh */
        $api = $fresh->api;
        $database = $fresh->database;
        $configuredDatabase = $fresh->configuredDatabase;
        $tablePrefix = $fresh->tablePrefix;
        $configuredTablePrefix = $fresh->configuredTablePrefix;
        $config = $fresh->config;

        try {
            $this->disconnect();
        } finally {
            $this->api = $api;
            $this->database = $database;
            $this->configuredDatabase = $configuredDatabase;
            $this->tablePrefix = $tablePrefix;
            $this->configuredTablePrefix = $configuredTablePrefix;
            $this->config = $config;
            $this->latestReadWriteTypeRetrieved = null;
        }
    }

    /**
     * Refuse a statement that could write, before anything is sent.
     *
     * @throws LogicException
     */
    protected function refuseWrites(): never
    {
        throw new LogicException('InfluxDB 3 SQL is read-only; write points through InfluxDB::writeApi().');
    }
}
