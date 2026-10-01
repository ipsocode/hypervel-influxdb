<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\InfluxQL;

use Closure;
use DateTimeInterface;
use Exception;
use InfluxDB2\ApiException;
use InfluxDB2\Client;
use InvalidArgumentException;
use Ipsocode\InfluxDB\InfluxQL\Grammars\Grammar;
use stdClass;

/**
 * Runs InfluxQL statements on one InfluxDB connection.
 *
 * One per connection, memoised by InfluxDBManager::influxql(), since its
 * QueryApi holds one HTTP client: never build one on a request path. A
 * statement run on it directly is sent as written; only the Builder refuses
 * what the connection's version lacks.
 *
 * @see docs/influxql.md#running-statements
 * @see docs/influxql.md#configuring-influxql
 */
abstract class Connection
{
    /**
     * The timestamp precisions the /query endpoint's `epoch` parameter accepts on InfluxDB 1.x and 2.x.
     *
     * @var list<string>
     */
    protected const array EPOCHS = ['ns', 'u', 'µ', 'ms', 's', 'm', 'h'];

    protected QueryApi $api;

    protected Grammar $grammar;

    protected string $name;

    protected ?string $database;

    protected ?string $retentionPolicy;

    protected ?string $epoch;

    /**
     * The version the server reported on /ping, once it has named one.
     */
    protected ?string $serverVersion = null;

    /**
     * @param Client $client the InfluxDB client the statements run on
     * @param array<string, mixed> $config the connection's config as the manager resolves it — `name`, `bucket` and the optional `influxql` block
     * @param null|QueryApi $api a transport to use instead of one built from the client's options
     * @param null|Grammar $grammar a grammar to use instead of the version's own
     *
     * @throws InvalidArgumentException when the configured `epoch` is not a precision the endpoint accepts, or the version has no retention policies and one is configured
     */
    public function __construct(
        protected Client $client,
        array $config = [],
        ?QueryApi $api = null,
        ?Grammar $grammar = null,
    ) {
        $options = (array) ($config['influxql'] ?? []);

        $this->name = (string) ($config['name'] ?? 'default');
        [$this->database, $this->retentionPolicy] = $this->resolveDatabase(
            $this->stringOrNull($options['database'] ?? null),
            $this->stringOrNull($options['retentionPolicy'] ?? null),
            $this->stringOrNull($config['bucket'] ?? $client->options['bucket'] ?? null),
        );
        $this->epoch = $this->resolveEpoch($this->stringOrNull($options['epoch'] ?? null));

        $this->api = $api ?? new QueryApi($client->options);
        $this->grammar = $grammar ?? $this->getDefaultQueryGrammar();
    }

    /**
     * Make the connection for the InfluxDB version its config names, `v1` when it names none.
     *
     * @param Client $client the InfluxDB client the statements run on
     * @param array<string, mixed> $config the connection's config as the manager resolves it — `name`, `version`, `bucket` and the optional `influxql` block
     *
     * @throws InvalidArgumentException when the configured `version` is not one this package implements, `epoch` is not a precision the endpoint accepts, or a `v3` connection names a retention policy
     */
    public static function make(Client $client, array $config = []): self
    {
        return match (Version::resolve($config['version'] ?? null, (string) ($config['name'] ?? 'default'))) {
            Version::V1 => new V1Connection($client, $config),
            Version::V2 => new V2Connection($client, $config),
            Version::V3 => new V3Connection($client, $config),
        };
    }

    /**
     * Begin a fluent query against a measurement.
     */
    public function table(Expression|Regex|string $measurement): Builder
    {
        return $this->query()->from($measurement);
    }

    /**
     * Get a new query builder instance.
     */
    public function query(): Builder
    {
        return new Builder($this, $this->grammar);
    }

    /**
     * Run a select statement and return the rows of its first result.
     *
     * Every series is flattened into one list of rows, each with its series'
     * GROUP BY tags appended; results() keeps the series apart.
     *
     * @param list<mixed> $bindings
     * @return list<stdClass>
     *
     * @throws QueryException
     */
    public function select(string $query, array $bindings = []): array
    {
        $results = $this->results($query, $bindings);

        return $results === [] ? [] : $results[0]->rows();
    }

    /**
     * Run a select statement and return the first row of its first result.
     *
     * @param list<mixed> $bindings
     *
     * @throws QueryException
     */
    public function selectOne(string $query, array $bindings = []): ?stdClass
    {
        return $this->select($query, $bindings)[0] ?? null;
    }

    /**
     * Run one or more statements and return one Result per statement.
     *
     * InfluxDB 2.x returns no Result for a DELETE or DROP MEASUREMENT that succeeds.
     *
     * @param list<mixed> $bindings
     * @return list<Result>
     *
     * @throws QueryException when a statement is refused, or never reaches the server
     */
    public function results(string $query, array $bindings = []): array
    {
        return $this->run($query, $bindings, function (string $sql) use ($bindings): array {
            $results = array_map(Result::fromArray(...), $this->api->query($sql, $this->queryParameters()));

            foreach ($results as $result) {
                if ($result->error !== null) {
                    throw new QueryException($this->name, $sql, $bindings, $result->error);
                }
            }

            return $results;
        });
    }

    /**
     * Run a statement whose result is not needed, such as `SELECT ... INTO` or `DELETE`.
     *
     * @param list<mixed> $bindings
     *
     * @throws QueryException
     */
    public function statement(string $query, array $bindings = []): bool
    {
        $this->results($query, $bindings);

        return true;
    }

    /**
     * Get a new raw query expression.
     */
    public function raw(string|int|float $value): Expression
    {
        return new Expression($value);
    }

    /**
     * Prepare the query bindings for embedding.
     *
     * A date becomes the RFC3339 string InfluxQL reads.
     *
     * @param list<mixed> $bindings
     * @return list<mixed>
     */
    public function prepareBindings(array $bindings): array
    {
        foreach ($bindings as $key => $value) {
            if ($value instanceof DateTimeInterface) {
                $bindings[$key] = $this->grammar->formatDateTime($value);
            }
        }

        return $bindings;
    }

    /**
     * Get the InfluxDB client this connection runs on.
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * Get the transport the statements are sent through.
     */
    public function getQueryApi(): QueryApi
    {
        return $this->api;
    }

    /**
     * Get the query grammar used by the connection.
     */
    public function getQueryGrammar(): Grammar
    {
        return $this->grammar;
    }

    /**
     * Get the connection name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the InfluxDB version the connection's server runs, which its grammar compiles for.
     */
    abstract public function getVersion(): Version;

    /**
     * Get the InfluxDB version the server reports, such as `1.8.10` or `v2.7.12`.
     *
     * The manager hands over the version detectServerVersions() read at Hypervel
     * server start; otherwise /ping is asked on first use, and again while the
     * server names none. The Builder reads it to refuse a server of another version.
     *
     * @return null|string the reported version, or null when the server names none
     *
     * @throws ApiException when the server cannot be reached, or answers with an error
     */
    public function getServerVersion(): ?string
    {
        return $this->serverVersion ??= $this->api->ping();
    }

    /**
     * Set the version the server reports, so getServerVersion() does not ask it.
     *
     * Null leaves it to be asked for on /ping.
     */
    public function setServerVersion(?string $version): static
    {
        $this->serverVersion = $version;

        return $this;
    }

    /**
     * Determine whether the server has named its version, so getServerVersion() will not ask it.
     */
    public function knowsServerVersion(): bool
    {
        return $this->serverVersion !== null;
    }

    /**
     * Get the database the statements are addressed to.
     */
    public function getDatabase(): ?string
    {
        return $this->database;
    }

    /**
     * Get the retention policy the statements are addressed to, if one is set.
     */
    public function getRetentionPolicy(): ?string
    {
        return $this->retentionPolicy;
    }

    /**
     * Get the precision timestamps come back in, or null for RFC3339 strings.
     */
    public function getEpoch(): ?string
    {
        return $this->epoch;
    }

    /**
     * Get the grammar the connection's version compiles with.
     */
    abstract protected function getDefaultQueryGrammar(): Grammar;

    /**
     * Embed the bindings and run the statement, wrapping any failure in a QueryException.
     *
     * @param list<mixed> $bindings
     * @param Closure(string): mixed $callback
     *
     * @throws QueryException
     */
    protected function run(string $query, array $bindings, Closure $callback): mixed
    {
        // Values are embedded rather than sent as the endpoint's `$name`
        // parameters, so the statement that runs is the one toRawSql() shows.
        $sql = $this->grammar->substituteBindingsIntoRawSql($query, $this->prepareBindings($bindings));

        try {
            return $callback($sql);
        } catch (QueryException $exception) {
            throw $exception;
        } catch (Exception $exception) {
            throw new QueryException($this->name, $sql, $bindings, $exception);
        }
    }

    /**
     * Get the query-string parameters every statement is sent with.
     *
     * @return array<string, null|string>
     */
    protected function queryParameters(): array
    {
        return [
            'db' => $this->database,
            'rp' => $this->retentionPolicy,
            'epoch' => $this->epoch,
        ];
    }

    /**
     * Resolve the database and retention policy the statements are addressed to.
     *
     * A database named in the influxql block is used as given, with the block's policy or
     * none; otherwise the bucket is split at its first slash, as 1.x and 2.x split one,
     * and the block's policy still wins over the bucket's.
     *
     * @return array{?string, ?string}
     */
    protected function resolveDatabase(?string $database, ?string $retentionPolicy, ?string $bucket): array
    {
        if ($database !== null || $bucket === null) {
            return [$database, $retentionPolicy];
        }

        [$database, $policy] = array_pad(explode('/', $bucket, 2), 2, null);

        return [$this->stringOrNull($database), $retentionPolicy ?? $this->stringOrNull($policy)];
    }

    /**
     * Check the configured epoch, and write microseconds as `u`.
     *
     * The endpoint's documentation lists `µ` too, but InfluxDB 1.x and 2.x read
     * only `u` and answer an unknown epoch in nanoseconds; InfluxDB 3 reads either.
     *
     * @throws InvalidArgumentException
     */
    private function resolveEpoch(?string $epoch): ?string
    {
        if ($epoch !== null && ! in_array($epoch, static::EPOCHS, true)) {
            throw new InvalidArgumentException(sprintf(
                'InfluxDB connection [%s] has an invalid influxql.epoch [%s]; expected one of %s.',
                $this->name,
                $epoch,
                implode(', ', static::EPOCHS),
            ));
        }

        return $epoch === 'µ' ? 'u' : $epoch;
    }

    /**
     * Read a config value as a non-empty string, or null.
     */
    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
