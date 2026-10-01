# Configuration

This page covers the connections in `config/influxdb.php`: the settings each
one takes, the InfluxDB version it names and what that changes, how to connect
to InfluxDB 1.x, 2.x and 3, and the options passed through to the InfluxDB
client.

## Connections

The default `main` connection reads its settings from the environment:

```dotenv
INFLUXDB_VERSION=v1
INFLUXDB_URL=http://localhost:8086
INFLUXDB_TOKEN=my-token
INFLUXDB_BUCKET=my-bucket
INFLUXDB_ORG=my-org
INFLUXDB_VERIFY_SSL=true
INFLUXDB_PRECISION=ns
INFLUXDB_DEBUG=false
```

`url`, `token`, `bucket` and `org` are required. A connection with one of them
missing or empty throws an `InvalidArgumentException` naming the connection and
the key when it is first resolved, and naming a connection with no entry under
`connections` throws one too. `verifySSL` defaults to `true`; set
`INFLUXDB_VERIFY_SSL=false` only for a server with a certificate you cannot
verify, such as a self-signed one in development. `precision` is the precision
the client writes timestamps in, `ns` unless set, and `debug`, `false` unless
set, makes the client log each request and response, headers and body, to
`logFile` (`php://output` unless set). A connection that leaves out
`verifySSL`, `precision` or `debug` gets these defaults.

The package's `config/influxdb.php` also reads `INFLUXDB_DATABASE`,
`INFLUXDB_RETENTION_POLICY` and `INFLUXDB_EPOCH` into the connection's
`influxql` block (see [Configuring InfluxQL](influxql.md#configuring-influxql)),
and `INFLUXDB_BATCHING`, `INFLUXDB_BATCH_SIZE`, `INFLUXDB_BATCH_SIZE_MB` and
`INFLUXDB_FLUSH_INTERVAL` into its `write` block (see
[Batching writes](writing.md#batching-writes)).

### More connections

More connections go under `connections` in `config/influxdb.php`, and
`INFLUXDB_CONNECTION` picks the default. The provider merges your file with the
package's by connection name: an entry you define replaces the package's entry
of the same name, and the package's `main` stays available alongside your own
connections unless you redefine it.

```php
'default' => env('INFLUXDB_CONNECTION', 'main'),

'connections' => [
    // `main` comes from the package's config unless you define it here.
    'analytics' => [
        'version' => 'v2',
        'url' => env('ANALYTICS_INFLUXDB_URL'),
        'token' => env('ANALYTICS_INFLUXDB_TOKEN'),
        'bucket' => 'analytics',
        'org' => 'my-org',
    ],
],
```

A connection is then named wherever one is taken, such as
`InfluxDB::writeApi('analytics')` or `InfluxDB::table('cpu', 'analytics')`: see
[Usage](../README.md#usage).

## Choosing the server version

`version` names the major version of InfluxDB the connection's server runs:
`v1`, the default, `v2` or `v3`. Writes and Flux queries do not depend on it,
since the InfluxDB client (`influxdata/influxdb-client-php`) speaks the 2.x
API, which InfluxDB 1.8 and later and InfluxDB 3 serve for compatibility
(InfluxDB 3 serves writes, not Flux).
[InfluxQL queries](influxql.md#the-query-builder) do: the builder, and the
[`influxql` database driver](influxql-driver.md#setting-up-the-influxql-driver),
compile for the version, and
[check it against the version the server reports](influxql.md#the-builder-checks-the-servers-version)
before they send their first statement.
[SQL](sql.md#setting-up-the-influxdb-driver) needs `v3`. Of the writes, only
the batch sizes a [batching writer](writing.md#when-a-batch-is-sent) defaults
to follow the version.

Any letter case works, and an unset or empty `version` is `v1`. Any other
value throws an `InvalidArgumentException`, naming the connection and the
value, when something that reads the version is first built for the
connection: its InfluxQL connection, its batching writer, or a database
connection over it.

| | `v1`: InfluxDB 1.8 or later | `v2`: InfluxDB 2.x | `v3`: InfluxDB 3 Core or Enterprise |
|---|---|---|---|
| `token` | `username:password`, or any non-empty value when authentication is off | An API token | A token, or any non-empty value when authentication is off |
| `org` | Required by the client, ignored by the server: `-` will do | The organization | Required by the client, ignored by the server |
| `bucket` | `database/retention-policy`, or `database` on its default policy | Any bucket, which InfluxQL reaches through a [DBRP mapping](#connecting-to-influxdb-2x) | The database, named whole: a slash is part of the name |
| `into()` | Runs `SELECT ... INTO` | Refused: 2.x has no `SELECT ... INTO`, so downsample with a task | Refused: 3 has no `SELECT ... INTO`, so downsample with its processing engine |
| `slimit()`, `soffset()` | Run | Run | Refused, unless 0, which is left out of the statement |
| [`delete()`](influxql.md#deleting-points) | Deletes from every retention policy of the database | Deletes from the database's default retention policy only, so it is refused on a connection that addresses a retention policy | Refused: 3 has no `DELETE`, so delete the table or the database |
| `epoch` | `ns`, `u`, `ms`, `s`, `m` or `h` | The same | Those, `d` and `w` |
| Hypervel's query builder over InfluxQL | Through the [`influxql` database driver](influxql-driver.md#setting-up-the-influxql-driver), with the refusals above | The same | The same |
| SQL | Not available | Not available | Through the [`influxdb` database driver](sql.md#setting-up-the-influxdb-driver) |

A refusal throws a `RuntimeException` before anything is sent: see
[The builder checks the server's version](influxql.md#the-builder-checks-the-servers-version).

## Connecting to InfluxDB 1.x

The InfluxDB client speaks the 2.x API, which InfluxDB 1.8 and later serve for
compatibility. A connection to a 1.x server looks like this:

```dotenv
INFLUXDB_VERSION=v1
INFLUXDB_URL=http://localhost:8086
INFLUXDB_TOKEN=username:password   # any non-empty value when auth is disabled
INFLUXDB_BUCKET=telegraf/autogen   # database/retention-policy
INFLUXDB_ORG=-                     # required by the client, ignored by 1.x
```

Writes then go to that database and retention policy, and InfluxQL queries
read from them, since InfluxQL splits the bucket at its first slash the same
way: see [Configuring InfluxQL](influxql.md#configuring-influxql).

## Connecting to InfluxDB 2.x

```dotenv
INFLUXDB_VERSION=v2
INFLUXDB_URL=http://localhost:8086
INFLUXDB_TOKEN=my-token
INFLUXDB_BUCKET=telegraf           # database telegraf, on its default policy
INFLUXDB_ORG=my-org
```

InfluxDB 2.x keeps points in buckets, and finds the bucket an InfluxQL
statement reads through the DBRP mapping of its database and retention
policy. Since 2.4, a bucket without an explicit mapping has a virtual one,
derived from its name the way the connection splits it: a bucket named
`telegraf/autogen` is database `telegraf` on retention policy `autogen`, and
one named `telegraf` is database `telegraf` on its default policy. So InfluxQL
reads the connection's bucket with no setup. To address a bucket by another
database name, create a mapping on the server (`influx v1 dbrp create`), and
set that mapping's database as `influxql.database`.

A virtual mapping is its database's default only for a bucket named without a
slash, and 2.x runs a `DELETE` on the default policy alone, so `delete()` needs
a connection that addresses a database alone: see
[Deleting points](influxql.md#deleting-points).

## Connecting to InfluxDB 3

```dotenv
INFLUXDB_VERSION=v3
INFLUXDB_URL=http://localhost:8181
INFLUXDB_TOKEN=apiv3_...           # any non-empty value when auth is disabled
INFLUXDB_BUCKET=telegraf           # the database
INFLUXDB_ORG=-                     # required by the client, ignored by 3
```

InfluxDB 3 Core and Enterprise keep points in databases, and have no
retention policies. What the InfluxDB client writes through the 2.x API lands
in the database named after the bucket, slash included: a bucket named
`telegraf/autogen` is database `telegraf/autogen`, created by the first write.
InfluxQL reads that same database, so a `v3` connection uses the bucket whole
rather than splitting it, and sends no retention policy. Set
`influxql.database` to read another database; an `influxql.retentionPolicy`
throws an `InvalidArgumentException` when the connection's InfluxQL connection
is built, since there is none to name. A measurement qualified with a database
reads the database the way InfluxDB 3 names it: `from('metrics.weekly.cpu')`
reads database `metrics/weekly`, but `from('metrics.autogen.cpu')` reads
`metrics`.

InfluxDB 3 runs the builder's statements as 1.x does, `tz()`, `fill()` and
regular expressions included, but has no `SELECT ... INTO`, `DELETE`, `SLIMIT`
or `SOFFSET`: a `v3` connection refuses `into()`, `delete()`, and `slimit()`
or `soffset()` with any value but 0, before anything is sent. Some InfluxQL
functions are missing on 3 as well: see
[What carries over from Hypervel's builder](influxql.md#what-carries-over-from-hypervels-builder).
InfluxDB 3 also answers SQL: see
[Querying InfluxDB 3 with SQL](sql.md#setting-up-the-influxdb-driver).

## Passing options to the client

A connection's config is passed to `InfluxDB2\Client` whole, so any option the
client accepts can be set on it, not only the keys shown above: `timeout`,
`proxy`, `allow_redirects`, `tags` (default tags for every point), `logFile`,
`httpClient` and the rest. Three keys are this package's own: `write`, the
write options `writeApi()` passes to the connection's `WriteApi`, which also
turn on [batching](writing.md#batching-writes), and `version`
([above](#choosing-the-server-version)) and `influxql`
([Configuring InfluxQL](influxql.md#configuring-influxql)), which are not
passed to the client.

```php
use InfluxDB2\WriteType;

'connections' => [
    'main' => [
        // url, token, bucket, org, ...
        'timeout' => 5,
        'tags' => ['service' => 'checkout'],
        'write' => [
            'writeType' => WriteType::SYNCHRONOUS, // the default
            'maxRetries' => 3,
        ],
    ],
],
```

`timeout`, `proxy` and `verifySSL` configure the Guzzle client that
`InfluxDB2\Client` builds for itself. With `httpClient` set to a PSR-18 client
of your own, they are not applied, so configure that client instead.
`allow_redirects`, `debug` and the token apply to either.

The package's own requests go through transports built from the same options:
InfluxQL queries, SQL queries on InfluxDB 3, and the batches a batching writer
sends. So these options apply to them as they do to writes and Flux queries.
