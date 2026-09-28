<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB;

use Exception;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Config\Repository;
use InfluxDB2\Client;
use InfluxDB2\WriteApi;
use InvalidArgumentException;
use Ipsocode\InfluxDB\InfluxQL\Builder;
use Ipsocode\InfluxDB\InfluxQL\Connection;
use Ipsocode\InfluxDB\InfluxQL\Expression;
use Ipsocode\InfluxDB\InfluxQL\QueryApi;
use Ipsocode\InfluxDB\InfluxQL\Regex;
use Ipsocode\InfluxDB\InfluxQL\Version;
use Ipsocode\InfluxDB\Write\BatchingWriter;
use Ipsocode\InfluxDB\Write\BatchOptions;

/**
 * Resolves and caches named InfluxDB connections.
 *
 * This is a native re-implementation of the behavior the Laravel package
 * inherited from graham-campbell/manager's AbstractManager, so the package
 * carries no Illuminate dependency under Hypervel.
 *
 * The connection cache is an instance property on this worker-lifetime
 * singleton (the standard Hypervel manager pattern); it is reset between
 * tests when the container is rebuilt.
 *
 * @mixin Client
 */
class InfluxDBManager
{
    /**
     * The non-coroutine context key of the versions the InfluxDB servers named when the Hypervel server started.
     */
    protected const string SERVER_VERSIONS = '__influxdb.server_versions';

    /**
     * The active connection instances, keyed by name.
     *
     * @var array<string, Client>
     */
    protected array $connections = [];

    /**
     * The memoised WriteApi instances, keyed by connection name.
     *
     * @var array<string, WriteApi>
     */
    protected array $writeApis = [];

    /**
     * The memoised InfluxQL connections, keyed by connection name.
     *
     * @var array<string, Connection>
     */
    protected array $influxql = [];

    public function __construct(
        protected Repository $config,
        protected InfluxDBFactory $factory,
    ) {
    }

    /**
     * Get an InfluxDB connection by name, creating it on first use.
     */
    public function connection(?string $name = null): Client
    {
        $name = $name ?: $this->getDefaultConnection();

        return $this->connections[$name] ??= $this->createConnection(
            $this->getConnectionConfig($name)
        );
    }

    /**
     * Get the memoised WriteApi for the given connection, creating it on first use.
     *
     * Upstream Client::createWriteApi() appends every call to an internal list that
     * never shrinks, so calling it directly on each request leaks a WriteApi (and its
     * own Guzzle client) for the worker's life. This caches one WriteApi per
     * connection instead — write() calls should go through this, not createWriteApi().
     *
     * With `'writeType' => WriteType::BATCHING` it is a BatchingWriter, which
     * buffers the worker's points and sends them in batches, in place of
     * upstream's batching WriteApi.
     *
     * @param null|array<string, mixed> $writeOptions the options to create it with, instead of the connection's `write` block
     *
     * @throws InvalidArgumentException when the connection is not configured, or its batching options are out of range
     */
    public function writeApi(?string $connection = null, ?array $writeOptions = null): WriteApi
    {
        $name = $connection ?: $this->getDefaultConnection();

        return $this->writeApis[$name] ??= $this->makeWriteApi(
            $name,
            $writeOptions ?? $this->getConnectionConfig($name)['write'] ?? null,
        );
    }

    /**
     * Send what the given connection's BatchingWriter has buffered, and wait for it.
     *
     * Nothing to do for a connection that writes synchronously, or has not
     * written yet.
     */
    public function flush(?string $name = null): void
    {
        $writeApi = $this->writeApis[$name ?: $this->getDefaultConnection()] ?? null;

        if ($writeApi instanceof BatchingWriter) {
            $writeApi->flush();
        }
    }

    /**
     * Send what every connection's BatchingWriter has buffered, and wait for it.
     *
     * The provider calls this when a console command finishes, so a command
     * does not wait out a flush interval before it exits.
     */
    public function flushAll(): void
    {
        foreach ($this->writeApis as $writeApi) {
            if ($writeApi instanceof BatchingWriter) {
                $writeApi->flush();
            }
        }
    }

    /**
     * Get the InfluxQL connection for the given connection, creating it on first use.
     *
     * Runs InfluxQL over the client's transport through the 1.x-compatible
     * /query endpoint, compiled for the InfluxDB version the connection's
     * `version` names: a V1Connection, a V2Connection or a V3Connection. It
     * is handed the version its InfluxDB server named when the Hypervel
     * server started, if it named one (getDetectedServerVersion()). One
     * instance per connection is kept for the worker's life, as writeApi()
     * keeps its WriteApi: each holds one HTTP client, and building one per
     * request would leak it.
     *
     * @throws InvalidArgumentException when the connection is not configured, names a version this package does not implement, or is misconfigured for its version
     */
    public function influxql(?string $name = null): Connection
    {
        $name = $name ?: $this->getDefaultConnection();

        return $this->influxql[$name] ??= Connection::make(
            $this->connection($name),
            $this->getConnectionConfig($name),
        )->setServerVersion($this->getDetectedServerVersion($name));
    }

    /**
     * Ask the server of every configured connection for its version, once for every worker.
     *
     * The provider calls this in the master process, outside any coroutine,
     * just before the Hypervel server forks its workers (BeforeServerFork).
     * The versions are kept in the non-coroutine context, which every worker
     * inherits, and read back by getDetectedServerVersion(), through which
     * influxql() hands each InfluxQL connection its server's, so no worker
     * asks again. Each ping goes through a transport of its own with
     * `Connection: close`, so no connection is left open for the workers to
     * inherit, even through an injected `httpClient` they share.
     *
     * A connection that is not fully configured, or whose server cannot be
     * reached or names no version, is left out: each worker's InfluxQL
     * connection asks its server before the builder's first statement
     * instead. A server that does not answer holds the start for up to the
     * connection's `timeout`.
     *
     * Boot only: inside a coroutine, the versions would be kept in that
     * coroutine's context, which getDetectedServerVersion() does not read.
     */
    public function detectServerVersions(): void
    {
        $versions = [];

        foreach (array_keys((array) $this->config->get($this->getConfigName() . '.connections', [])) as $name) {
            try {
                $options = $this->factory->make($this->getConnectionConfig((string) $name))->options;

                $version = (new QueryApi($options))->ping(['Connection' => 'close']);
            } catch (Exception) {
                continue;
            }

            if ($version !== null) {
                $versions[(string) $name] = $version;
            }
        }

        CoroutineContext::set(self::SERVER_VERSIONS, $versions);
    }

    /**
     * Get the version the given connection's InfluxDB server named when the Hypervel server started.
     *
     * Read from the non-coroutine context detectServerVersions() fills in the
     * master process, so a worker's coroutines read it too. Null when the
     * server was not asked, could not be reached or named no version, or no
     * connection has that name: a connection handed null asks its server
     * itself, before its first statement.
     */
    public function getDetectedServerVersion(string $name): ?string
    {
        return CoroutineContext::getFromNonCoroutine(self::SERVER_VERSIONS, [])[$name] ?? null;
    }

    /**
     * Get a new InfluxQL query builder on the given connection.
     */
    public function query(?string $connection = null): Builder
    {
        return $this->influxql($connection)->query();
    }

    /**
     * Begin an InfluxQL query against a measurement on the given connection.
     */
    public function table(Expression|Regex|string $measurement, ?string $connection = null): Builder
    {
        return $this->influxql($connection)->table($measurement);
    }

    /**
     * Disconnect and rebuild the given connection.
     *
     * Boot or tests only. Replaces the entry in the worker-shared connection
     * cache; concurrent coroutines may be left holding the previous client.
     */
    public function reconnect(?string $name = null): Client
    {
        $name = $name ?: $this->getDefaultConnection();

        $this->disconnect($name);

        return $this->connection($name);
    }

    /**
     * Forget the given connection instance.
     *
     * The WriteApi and InfluxQL connection memoised on top of it go with it:
     * both hold the client they were built from, and would otherwise keep
     * writing and querying through a client the manager has dropped. A
     * BatchingWriter is closed first, so what it has buffered is sent rather
     * than dropped with it.
     *
     * Boot or tests only. Mutates the worker-shared connection cache; dropping
     * a connection while other coroutines use it affects the whole worker.
     */
    public function disconnect(?string $name = null): void
    {
        $name = $name ?: $this->getDefaultConnection();

        $writeApi = $this->writeApis[$name] ?? null;

        unset($this->connections[$name], $this->writeApis[$name], $this->influxql[$name]);

        if ($writeApi instanceof BatchingWriter) {
            $writeApi->close();
        }
    }

    /**
     * Build a fresh client from the given connection config.
     */
    protected function createConnection(array $config): Client
    {
        return $this->factory->make($config);
    }

    /**
     * Build the WriteApi for a connection: a BatchingWriter when its options ask for batching.
     *
     * A BatchingWriter is built on the connection's client options rather
     * than through Client::createWriteApi(), which would keep a reference to
     * it for close(); the manager closes it instead, on disconnect().
     *
     * @param null|array<string, mixed> $writeOptions
     *
     * @throws InvalidArgumentException when the batching options are out of range, or the connection names a version this package does not implement
     */
    protected function makeWriteApi(string $name, ?array $writeOptions): WriteApi
    {
        $client = $this->connection($name);

        if (! BatchOptions::batching($writeOptions)) {
            return $client->createWriteApi($writeOptions);
        }

        return new BatchingWriter(
            $client->options,
            $name,
            BatchOptions::fromConfig(
                (array) $writeOptions,
                Version::resolve($this->getConnectionConfig($name)['version'] ?? null, $name),
                $name,
            ),
            $writeOptions,
        );
    }

    /**
     * Resolve the config for a named connection.
     *
     * @throws InvalidArgumentException when the connection is not configured
     */
    public function getConnectionConfig(string $name): array
    {
        $connections = (array) $this->config->get($this->getConfigName() . '.connections', []);

        $config = $connections[$name] ?? null;

        if (! is_array($config) || $config === []) {
            throw new InvalidArgumentException("InfluxDB connection [{$name}] is not configured.");
        }

        $config['name'] = $name;

        return $config;
    }

    /**
     * Get the default connection name.
     */
    public function getDefaultConnection(): string
    {
        return (string) $this->config->get($this->getConfigName() . '.default');
    }

    /**
     * Set the default connection name.
     *
     * Boot-only. Writes to the process-global config repository, so a
     * per-request call leaks the new default to every concurrent coroutine on
     * the worker. To target a connection at runtime, use connection($name).
     */
    public function setDefaultConnection(string $name): void
    {
        $this->config->set($this->getConfigName() . '.default', $name);
    }

    /**
     * Get all of the resolved connection instances.
     *
     * @return array<string, Client>
     */
    public function getConnections(): array
    {
        return $this->connections;
    }

    /**
     * Get the config key that holds this manager's connections.
     */
    public function getConfigName(): string
    {
        return 'influxdb';
    }

    /**
     * Get the underlying client factory.
     */
    public function getFactory(): InfluxDBFactory
    {
        return $this->factory;
    }

    /**
     * Pass dynamic method calls through to the default connection.
     *
     * @param array<int, mixed> $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->connection()->{$method}(...$parameters);
    }
}
