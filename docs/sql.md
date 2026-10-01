# Querying InfluxDB 3 with SQL

This page covers the `influxdb` database driver, which runs Hypervel's query
builder, raw queries and read-only Eloquent models on InfluxDB 3's SQL API:
how to set up a connection, how to query it, and where it behaves differently
from a PostgreSQL database.

## Setting up the `influxdb` driver

InfluxDB 3 also answers SQL, which it plans with Apache DataFusion, a dialect
that follows PostgreSQL's. The package registers it as an `influxdb` database
driver, so Hypervel's own query builder, raw queries and read-only Eloquent
models run on an InfluxDB 3 database: joins, unions and window functions
included.

Add a connection to your application's `config/database.php`. It names the
InfluxDB connection it runs on, whose `version` must be `v3` (see
[Connecting to InfluxDB 3](configuration.md#connecting-to-influxdb-3)), and the
database, which defaults to that connection's bucket:

```php
'connections' => [
    // mysql, pgsql, ...

    'influxdb' => [
        'driver' => 'influxdb',
        'connection' => 'main',       // an InfluxDB connection with 'version' => 'v3'; the default one when left out
        // 'database' => 'telegraf',  // that connection's bucket when left out
    ],
],
```

`DB::connection('influxdb')` throws an `InvalidArgumentException` when the
InfluxDB connection it names is not [configured](configuration.md#connections),
lacks one of its required keys, or has a `version` other than `v3`. That
includes a connection with no `version`, which is `v1`: see
[Choosing the server version](configuration.md#choosing-the-server-version).
The `version` is taken as configured: unlike the `influxql` driver, this
driver does not ask the server which version it runs before it sends a
statement.

On any InfluxDB version, the `influxql` driver runs InfluxQL instead: see
[Setting up the `influxql` driver](influxql-driver.md#setting-up-the-influxql-driver).

## Querying

Query it as any other database connection:

```php
use Hypervel\Database\Query\JoinClause;
use Hypervel\Support\Facades\DB;

$influxdb = DB::connection('influxdb');

$rows = $influxdb->table('cpu')
    ->select('host', 'usage_user', 'time')
    ->where('host', 'web1')
    ->where('time', '>=', now()->subHour())
    ->orderByDesc('time')
    ->limit(10)
    ->get();

$joined = $influxdb->table('cpu')
    ->join('mem', function (JoinClause $join) {
        $join->on('cpu.host', '=', 'mem.host')->on('cpu.time', '=', 'mem.time');
    })
    ->select('cpu.host', 'cpu.usage_user', 'mem.used')
    ->get();

$hosts = $influxdb->table('cpu')->select('host')
    ->union($influxdb->table('mem')->select('host'))
    ->pluck('host');

$running = $influxdb->table('mem')
    ->select('host', 'used')
    ->selectRaw('sum("used") over (partition by "host" order by "time") as "running"')
    ->get();

$means = $influxdb->select(
    'select "host", avg("usage_user") as "mean" from "cpu" where "time" >= ? group by "host"',
    [now()->subDay()],
);
```

The builder compiles with Hypervel's PostgreSQL grammar, since DataFusion's
dialect follows PostgreSQL's: double-quoted identifiers, the `~` family of
regular-expression operators, `ilike`, `::` casts, `extract()`,
`distinct on`, joins, unions and window functions. So
`where('host', '~', '^web')` matches a regular expression, and `whereLike()`
compiles to `ilike`, or to `like` when it is case-sensitive.
[What to expect](#what-to-expect) lists where the grammar differs.

`select()`, `selectOne()`, `scalar()` and `cursor()` run a statement you write
out, its `?` placeholders filled in order by the bindings. A `?` is a
placeholder only outside string literals, quoted identifiers and comments, and
`??` is a literal question mark, as it is for PDO. These are found the way
DataFusion reads the statement: a doubled quote escapes itself in a literal or
an identifier, and a backslash escapes the next character only in an `E'...'`
string.

The connection is one of Hypervel's database connections, so its query
events, query log and `pretend()` work as they do on any other. `pretend()`
sends nothing, and logs each statement with its values embedded, as it would
be sent. A statement bound to a date is the exception: to log it, Hypervel
hands the grammar the bindings as they were given, before the connection
writes a date as a string, so `pretend()` throws a `TypeError` on the date.
`setDatabaseName()` sends the connection's statements to another database
until Hypervel's pool takes the connection back and resets it.

An Eloquent model reads through the connection too: extend
`Ipsocode\InfluxDB\Eloquent\Measurement`, which makes
[the settings a time series needs](eloquent.md#the-settings-a-time-series-needs)
and refuses to write. See
[Defining a measurement](eloquent.md#defining-a-measurement) for a model on
this connection.

## What to expect

- **It is read-only.** InfluxDB 3 answers SQL reads only, so inserts,
  updates, upserts, deletes and truncates, an Eloquent `save()` included,
  throw a `LogicException` before anything is sent, as do `statement()`,
  `affectingStatement()` and `unprepared()`. Write points through
  [`InfluxDB::writeApi()`](writing.md#writing-through-writeapi). There are no
  transactions either: `transaction()` and `beginTransaction()` throw a
  `LogicException`, `inTransaction()` is always false, and `lockForUpdate()`
  and `sharedLock()` compile to nothing, as they do on SQLite. A lock given
  as a string, such as `lock('for update')`, is written as given.
- **The values are embedded in the statement.** Each statement is one POST to
  `/api/v3/query_sql`, sent in a JSON body with the database, and with its
  values escaped into it, so what is sent is what `toRawSql()` shows. A string
  is quoted with its `'` doubled, the only escape DataFusion reads, and a
  backslash is itself. A boolean is `TRUE` or `FALSE`, not the 0 or 1
  Hypervel sends to a PDO database, since DataFusion does not compare a
  boolean with a number. A date is RFC3339 with microseconds and its own
  offset, such as `2024-01-01T02:00:00.000000+02:00`, so it compares on the
  instant it names, whatever its time zone. A statement or string that is not
  valid UTF-8, a string with a null byte, and a binary value cannot be
  embedded: each throws a `Hypervel\Database\QueryException` before anything
  is sent.
- **Rows are objects keyed by column.** InfluxDB 3 leaves a column out of a
  row whose value is null, so each row is given every column the other rows
  of the result have, null where it was left out. A column that is null in
  every row cannot be told from one that was never selected, and is not given.
  Values keep their JSON types, and an integer too large for PHP comes back as
  a numeric string. Timestamps come back in UTC without an offset, such as
  `2024-01-01T00:00:00`.
- **`cursor()` reads the whole result at once.** InfluxDB 3 answers a
  statement with all its rows, so `cursor()` sends one request when it is
  first iterated, reads every row as `select()` does, and then yields them one
  at a time.
- **`exists()` selects a row only when one exists**, as
  `select true as "exists" where exists(...)`, since DataFusion cannot select
  the value of an `EXISTS`.
- **What DataFusion lacks is refused by the server.** JSON clauses such as
  `whereJsonContains()` and full-text search compile as they do for
  PostgreSQL, and come back as a `Hypervel\Database\QueryException`, as any
  statement the server refuses does. There is no schema builder:
  `Schema::connection('influxdb')` fails.

A statement the server refuses, or one that never reaches it, throws a
`Hypervel\Database\QueryException` that names the database connection, the
InfluxDB server's host and port, taken from the InfluxDB connection's `url`,
the database, and the statement:

```text
[400] Error connecting to the API (http://localhost:8181/api/v3/query_sql)(Error during planning: table 'public.iox.nothere' not found) (Connection: influxdb, Host: localhost, Port: 8181, Database: telegraf, SQL: select * from "nothere" where "host" = web1)
```

Its `getRawSql()` returns the statement exactly as it was sent. For a
statement the connection tried to send, its previous exception is the InfluxDB
client's `InfluxDB2\ApiException`, which carries the server's message and HTTP
status, or the transport's error. A statement that fails after the server has
started sending its rows leaves an answer that is not a list of rows, and
throws the same way. A statement that fails because the connection was lost
is sent once more, on a new connection.

The connections are Hypervel's: its database manager makes them through the
driver, and its connection pool keeps them, one per coroutine at a time, each
with its own HTTP transport (see
[Long-lived workers](hypervel.md#long-lived-workers)). The InfluxDB
connection's options, such as `timeout`, `proxy`, `verifySSL` and
`httpClient`, apply to that transport as they do to writes: see
[Passing options to the client](configuration.md#passing-options-to-the-client).
The pool's health check asks the server on `/ping`, and `getServerVersion()`
asks it there each time it is called. It returns the version the server names,
such as `3.11.5`, or an empty string for a server that names none, and throws
an `InfluxDB2\ApiException` when the server cannot be reached or does not
answer as InfluxDB 3 does. A disconnected connection lets go of its transport
and builds a new one for its next statement. InfluxDB 3 serves SQL over
stateless HTTP requests, so `threadCount()` returns `null`.
