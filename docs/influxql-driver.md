# Hypervel's query builder over InfluxQL

This page covers the `influxql` database driver, which runs Hypervel's own
query builder, raw statements and Eloquent models on InfluxQL, on any InfluxDB
version: how to set up a connection, how to query it, and where it behaves
differently from a SQL database.

## Setting up the `influxql` driver

`InfluxDB::table()` is the package's own builder, shaped like Hypervel's: see
[The query builder](influxql.md#the-query-builder). The package also registers
an `influxql` database driver, which runs Hypervel's query builder itself on
InfluxQL, on any InfluxDB version. Its connections are Hypervel's database
connections, with their pool, events, query log and `pretend()`. Add one to
your application's `config/database.php`, naming the InfluxDB connection it
runs on:

```php
'connections' => [
    // mysql, pgsql, ...

    'metrics' => [
        'driver' => 'influxql',
        'connection' => 'main',   // an InfluxDB connection of any version; the default one when left out
    ],
],
```

That InfluxDB connection's config decides the rest. The statements are compiled
for its [`version`](configuration.md#choosing-the-server-version), and sent to
the database and retention policy it reads, as
[configured](influxql.md#configuring-influxql) for `InfluxDB::table()`, with
its `epoch`. Each statement is one POST to `/query`, and the InfluxDB
connection's options, such as `timeout`, `proxy`, `verifySSL` and `httpClient`,
apply to it as they do to writes: see
[Passing options to the client](configuration.md#passing-options-to-the-client).
The host and port the connection names in its exceptions are those of the
InfluxDB connection's `url`.

`DB::connection('metrics')` throws an `InvalidArgumentException` when the
InfluxDB connection it names is not [configured](configuration.md#connections),
or is misconfigured for its version. Hypervel's pool keeps the connections:
see [Long-lived workers](hypervel.md#long-lived-workers).
`DB::reconnect('metrics')` reads the InfluxDB connection's config again for
its `version`, database, retention policy and `epoch`; the `url`, token and
other client options are those of the client the
[InfluxDB manager keeps](hypervel.md#the-connection-manager) for that
connection.

InfluxDB 3 also answers SQL, through the `influxdb` driver: see
[Setting up the `influxdb` driver](sql.md#setting-up-the-influxdb-driver).

## Querying

Query it as any other database connection:

```php
use Hypervel\Support\Facades\DB;

$metrics = DB::connection('metrics');

$rows = $metrics->table('cpu')
    ->select('usage_user', 'host')
    ->where('host', '=~', '^web')
    ->where('time', '>=', now()->subHour())
    ->latest()                              // by time
    ->limit(10)
    ->get();

$hourly = $metrics->table('cpu')
    ->selectRaw('mean("usage_user") as "mean"')
    ->where('time', '>=', now()->subDay())
    ->groupByTime('1h')
    ->fill('previous')
    ->pluck('mean', 'time');

$perHost = $metrics->table('cpu')
    ->selectRaw('max("usage_user")')
    ->groupBy('host')
    ->series();                             // one series per host, its tags given once
```

Eloquent models read through the connection too: see
[Models of measurements](eloquent.md#defining-a-measurement).

It has the clauses [InfluxQL adds](influxql.md#what-influxql-adds), with the
same names: `groupByTime()`, `fill()`, `slimit()`, `soffset()`, `tz()`,
`into()` and regular expressions. It refuses what the connection's version
cannot run, as `InfluxDB::table()` does: see
[Choosing the server version](configuration.md#choosing-the-server-version).

### Raw fragments and subqueries

Raw fragments are Hypervel's: `DB::raw()`, and `selectRaw()`, `whereRaw()`,
`fromRaw()` and the other raw methods, which embed what they are given as
written, with their `?` bindings embedded as values. The package's
`InfluxQL\Expression` belongs to `InfluxDB::table()`, and is refused here. A
measurement pattern is a `Regex` bound into `fromRaw()`, and a subquery in
`from()` is compiled unnamed, as InfluxQL writes one:

```php
use Ipsocode\InfluxDB\InfluxQL\Regex;

$metrics->query()->fromRaw('?', [new Regex('^(cpu|mem)$')])->get();   // from /^(cpu|mem)$/

$busy = $metrics->query()
    ->select('mean')
    ->from(fn ($query) => $query->from('cpu')
        ->selectRaw('mean("usage_user") as "mean"')
        ->where('time', '>=', now()->subDay())
        ->groupByTime('10m'))
    ->where('mean', '>', 0.5)               // InfluxQL has no HAVING
    ->get();
```

Naming the subquery, as `from($query, 'sub')`, throws an
`InvalidArgumentException`.

### Statements you write

The connection runs statements you write out, with `?` placeholders:

```php
$rows = $metrics->select(
    'select mean("usage_user") from "cpu" where "host" =~ ? and time > ? group by time(1h)',
    [new Regex('^web'), now()->subDay()],
);

$series = $metrics->series('select max("usage_user") from "cpu" group by "host"');

$metrics->statement('drop measurement "cpu"');
```

`select()` returns the rows of the first statement's result, read as the
builder reads them, and `selectOne()` its first row; `series()` returns that
result's series. `statement()` and `unprepared()` return true once the server
accepts the statement, and `delete()` and `affectingStatement()` return 0,
since InfluxQL reports no count. `cursor()` reads the whole result in one
request, as `select()` does, and then yields its rows one at a time. A
statement you write is sent as written, not checked against what the
connection's version runs, but the server's version is checked before it is
sent, as it is for the builder's statements. That differs from
`InfluxDB::influxql()`, which checks neither: see
[Running statements](influxql.md#running-statements).

## What to expect

- **What InfluxQL cannot express is refused before anything is sent.** Each
  of these throws an `InvalidArgumentException` or a `RuntimeException`, as
  the method is called or as the statement compiles:
  - joins, havings and unions;
  - sub-selects among the columns or in a where clause, such as the one
    Eloquent's `withCount()` and `withSum()` add;
  - null comparisons, such as `whereNull()` or `where()` with a null value;
  - `whereNot()`, `whereNone()`, `whereLike()` and `whereExists()`;
  - the JSON, full-text and vector clauses;
  - the date-part clauses, such as `whereDate()`, `whereYear()` and
    `whereToday()`;
  - an SQL operator such as `like`; InfluxQL has `=`, `<`, `>`, `<=`, `>=`,
    `<>`, `!=`, `=~` and `!~`;
  - an order by anything but `time`, `inOrderOf()` and `inRandomOrder()`;
  - `distinct()` given columns, since InfluxQL has no `DISTINCT ON`;
    `distinct()` without them wraps each selected field in InfluxQL's
    `distinct()`;
  - `groupLimit()`, which needs a window function; a `limit()` on a query
    grouped by tags already applies to each series.

  `wherePast()` and `whereFuture()` compare `time` with now, and work, as do
  `whereAny()` and `whereAll()`. `whereIn()` and `whereBetween()` compile to
  the comparisons they stand for, as they do on
  [`InfluxDB::table()`](influxql.md#what-carries-over-from-hypervels-builder):
  an empty `whereIn()` matches nothing, and `whereBetween()` takes the first
  two bounds, or a `CarbonPeriod`'s start and end. Keep `time` out of
  `whereIn()`, `whereNotBetween()` and `orWhere()`: see
  [What InfluxQL lacks](influxql.md#what-influxql-lacks).

  One null check is let through: `whereNotNull()` on columns the query already
  compares with `=`, both ANDed, is dropped, since a point that matches the
  comparison has the value. That is the check an Eloquent relation adds to its
  query, so its queries compile: see
  [The settings a time series needs](eloquent.md#the-settings-a-time-series-needs).

  Writes, `truncate()` and transactions throw a `LogicException`; write points
  through [`InfluxDB::writeApi()`](writing.md#writing-through-writeapi).
  `lockForUpdate()`, `sharedLock()`, `timeout()` and index hints such as
  `useIndex()` compile to nothing.
- **A column qualified by the measurement is taken unqualified.** `cpu.time`
  is read as `time`, since InfluxQL names a field or tag on its own; Eloquent
  qualifies columns this way. On a qualified measurement, such as
  `telegraf.autogen.cpu`, a column is qualified by that whole name:
  `telegraf.autogen.cpu.time`. A column of another measurement is left as it
  is. A field whose own name starts with the measurement's name and a dot has
  to be given as an expression, such as `DB::raw('"cpu.load"')`. Otherwise a
  column is never split on dots: `disk.used` is one field. An alias
  (`usage_user as u`) and a type hint (`host::tag`) are written as InfluxQL
  writes them.
- **A measurement is named as InfluxQL names one.** A dotted name is the
  qualified `database.retention_policy.measurement`, as on `InfluxDB::table()`,
  and `telegraf..cpu` reads the database's default retention policy. A
  measurement whose own name has a dot is given as an expression, such as
  `DB::raw('"disk.io"')`. InfluxQL has no alias for a measurement, so
  `from('cpu', 'c')` throws an `InvalidArgumentException`, and no table prefix,
  so the connection's `prefix` is not applied.
- **Rows are read as InfluxQL returns them.** Each is an object keyed by
  column, `time` first, with the `GROUP BY` tags of its series appended.
  `time` is an RFC3339 string, or an integer when the InfluxDB connection sets
  `epoch`. `value()`, `pluck()` and the aggregates read the field they asked
  for. For a column the server names itself, such as the `mean` of
  `mean("usage_user")`, they read the first field after `time`. `avg()` is
  InfluxQL's `mean()`, and an aggregate over no points is `null`, except for
  `count()`, `sum()` and `numericAggregate()`, which return 0. `exists()`
  asks for one point. `series()` returns the series themselves, each with its
  `name`, `tags`, `columns` and `values`.
- **`count()` counts a field.** With no column, InfluxQL counts every field on
  its own, and `count()` returns the count of the first field, in alphabetical
  order. So name the field to count: `count('usage_user')`. `paginate()` counts
  with no column, so where fields are written sparsely, give it a total of
  your own, such as `total: $query->clone()->count('usage_user')`. InfluxQL
  cannot count the groups of a grouped query, so `paginate()` refuses one
  without its total.
- **`time` orders the points, but does not identify one.** `orderBy()` takes
  `time` only, and `latest()` and `oldest()` default to it. `chunk()`,
  `lazy()` and `cursorPaginate()` order by it when the query has no order.
  Points of different series can share a timestamp. `cursorPaginate()` pages
  by `time`, so it skips any point that shares the timestamp the page before
  ended on. `chunk()` and `lazy()` page by offset and see every point.
  `chunkById()` and `lazyById()` are refused.
- **`delete()`** deletes as [`InfluxDB::table()`'s does](influxql.md#deleting-points)
  on each version. It takes no id, and returns 0, since InfluxQL reports no
  count. A join, a limit, an offset, a slimit or a soffset would narrow which
  points or series it deletes, which InfluxQL's `DELETE` cannot, so they throw
  a `RuntimeException`.
- **The values are embedded in the statement**, as `InfluxDB::table()` embeds
  them, so what is sent is what `toRawSql()` shows, and what `pretend()`
  logs; `pretend()` sends nothing, not even the version check. A date is
  sent in UTC, with microseconds: `'2024-01-01T00:00:00.000000Z'`. A boolean
  is `true` or `false`, where Hypervel sends 0 or 1 to a SQL database, since
  InfluxQL compares a boolean field with `true` or `false` only. A `Regex` is
  sent as `/pattern/`. A value InfluxQL has no literal for, such as null or an
  array, throws before anything is sent, the version check included.
- **The database is the InfluxDB connection's.** A `database` set in
  `config/database.php` is replaced by the database the statements go to, and
  `setDatabaseName()` does not redirect them. To read another database, use
  another InfluxDB connection or a qualified measurement, such as
  `from('telegraf.weekly.cpu')`.
- **Errors are Hypervel's.** A statement the server refuses, or one that never
  reaches it, throws a `Hypervel\Database\QueryException`, not the package's
  `InfluxQL\QueryException`. It names the database connection, the server's
  host and port, the database and the statement:

  ```text
  database not found: telegraf (Connection: metrics, Host: localhost, Port: 8086, Database: telegraf, SQL: select * from "cpu" where "host" = web1)
  ```

  Its `getRawSql()` returns the statement exactly as it was sent. Its previous
  exception is a `RuntimeException` carrying the server's message, the
  `InfluxDB2\ApiException` of a request that failed, or the
  `InvalidArgumentException` of a value InfluxQL has no literal for. A server
  that runs another major version than the InfluxDB connection names, or
  names none, throws one too, before the statement is sent. Each pooled
  connection asks for the version before its first statement, and again after
  it reconnects, unless it was
  [read when the server started](influxql.md#the-builder-checks-the-servers-version);
  `getServerVersion()` returns that version, such as `1.8.10` or `v2.7.12`,
  asking for it first if need be, or an empty string for a server that names
  none. A statement that fails because the connection was lost is sent once
  more, on a new connection.
