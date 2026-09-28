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
 * The InfluxQL twin of Hypervel\Database\Connection, minus what has no
 * meaning here (transactions, PDO, prepared statements). It is the object a
 * Builder is bound to: `table()` and `query()` start one, `select()` runs the
 * statement it compiles, and `raw()` makes the expressions it embeds.
 *
 * Statements go to the /query endpoint InfluxDB 1.x defines, which 2.x and 3
 * also serve. What differs between them lives in one subclass per version,
 * as Hypervel has one per database driver: V1Connection, V2Connection and
 * V3Connection, which make() picks from the connection's `version`, each with
 * a grammar that refuses what its server does not run. Statements run on a
 * connection are sent as given; only the Builder compiles for the version,
 * and checks that the server runs it before it sends one.
 *
 * The endpoint addresses data by database and retention policy rather than
 * by bucket. Both come from the connection's `influxql` config block, or else
 * from its bucket split at the first slash — `db/rp`, or `db` on the
 * database's default policy — which is how 1.x's 2.x-compatibility API and
 * 2.x's virtual DBRP mappings read a bucket name. InfluxDB 3 has no retention
 * policies and stores the bucket's points under its whole name, so
 * V3Connection reads the bucket unsplit.
 *
 * Every statement goes through one QueryApi, which holds one HTTP client, so
 * a Connection is built once per named connection and memoised by the
 * manager; resolve it through InfluxDBManager::influxql() rather than
 * constructing it on a request path.
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
     * @param Client $client the upstream client whose transport and options the statements use
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
     * Make the connection for the InfluxDB version its config names.
     *
     * The config's `version` picks the class: V1Connection for `v1`, which is
     * the default, V2Connection for `v2` and V3Connection for `v3`.
     *
     * @param Client $client the upstream client whose transport and options the statements use
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
     * Every series of the result is flattened into one list of row objects
     * keyed by column, with the GROUP BY tags of each series appended to its
     * rows. Use results() to keep the series apart.
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
     * InfluxDB 2.x returns no Result for a DELETE or DROP MEASUREMENT that
     * succeeds, so a request that holds one gets one Result fewer; each Result
     * keeps the `statementId` of the statement it answers.
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
     * A date becomes the RFC3339 literal InfluxQL reads, as Hypervel formats
     * dates to its grammar's date format before binding them.
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
     * Get the upstream client this connection runs on.
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
     * The manager hands a connection the version its server named when the
     * Hypervel server started (InfluxDBManager::detectServerVersions()).
     * Otherwise it is read from /ping the first time it is asked for, and kept
     * for the connection's life once the server has named one; a server that
     * names none is asked again next time. The Builder reads it before its first
     * statement, to refuse a server that runs another version than the
     * connection's.
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
     * Whether the server has already named its version, so getServerVersion() will not ask it.
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
        // The values are embedded here, escaped by the grammar, rather than
        // sent as the endpoint's `$name` parameters, so the statement that runs
        // is exactly the one toRawSql() shows.
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
     * A database named in the influxql block is used as given, with that
     * block's retention policy or none. Otherwise both come from the bucket,
     * split at its first slash as InfluxDB 1.x and 2.x split one, and a
     * retention policy in the block still wins over the bucket's.
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
     * The endpoint's documentation lists `µ` as well, but InfluxDB 1.x and
     * 2.x read only `u`, and answer any value they do not know in
     * nanoseconds. InfluxDB 3 reads either, and refuses a value it does not
     * know, so `u` is what every version is sent.
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
