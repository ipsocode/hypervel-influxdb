# Querying with InfluxQL

This page covers reading points back with InfluxQL: the package's query
builder, the statements you write out yourself, deleting points, and the
`influxql` block that says which database and retention policy a connection's
statements address. Hypervel's own query builder can run over InfluxQL too,
through the [`influxql` database driver](influxql-driver.md#setting-up-the-influxql-driver).

## The query builder

Points can be read back with InfluxQL, through a query builder shaped like
Hypervel's `Query\Builder`. It sends InfluxQL to the `/query` endpoint that
InfluxDB 1.x defines and 2.x and 3 also serve, compiled for the connection's
[`version`](configuration.md#choosing-the-server-version):

```php
use Ipsocode\InfluxDB\Facades\InfluxDB;

$rows = InfluxDB::table('cpu')
    ->select('usage_user', 'host')
    ->where('host', 'web1')
    ->where('time', '>=', now()->subHour())
    ->latest()
    ->limit(10)
    ->get();                                // a Collection of stdClass rows

$mean = InfluxDB::table('cpu')->where('host', 'web1')->avg('usage_user');

InfluxDB::table('cpu', 'analytics');        // on a named connection
InfluxDB::query('analytics')->from('cpu');  // the same, started empty
```

Each row is an object keyed by column, `time` first, with the `GROUP BY` tags
of its series appended; a column wins over a tag of the same name. `series()`
returns the series themselves instead, as `Ipsocode\InfluxDB\InfluxQL\Series`
objects, each with its `name`, `tags`, `columns` and `values`, and `partial`,
which is true when the server truncated the series at its `max-row-limit`.

`toSql()` shows the statement with `?` placeholders, `getBindings()` the values
that fill them, and `toRawSql()` shows the statement as it is sent, with the
values escaped and embedded: they travel in the statement, not as parameters
of the endpoint, so what runs is exactly what `toRawSql()` shows. A date is
embedded as an RFC3339 timestamp in UTC, a backed enum as its value, and a
unit enum as its name.

## Running statements

The builders run on one InfluxQL connection per configured connection,
`InfluxDB::influxql($name)`, which the manager keeps for the worker's life (see
[The connection manager](hypervel.md#the-connection-manager)). It also runs
statements you write out:

```php
$rows = InfluxDB::influxql()->select(
    'SELECT MEAN("usage_user") FROM "cpu" WHERE "host" = ? GROUP BY time(1h)',
    ['web1'],
);
```

`select()` returns the rows of the first statement, its series flattened in
order, and `selectOne()` its first row, or `null`. `results()` returns one
`Ipsocode\InfluxDB\InfluxQL\Result` per `;`-separated statement, each with
the `statementId` of the statement it answers, its `series`, its `partial`
flag, and `rows()` to flatten them. `statement()` returns true once the
server accepts a statement whose result is not needed. InfluxDB 2.x returns no
`Result` for a `DELETE` or a `DROP MEASUREMENT` that succeeds, so a request
that holds one gets one `Result` fewer: match them up by `statementId`.

Unlike the builder's statements, these are sent as written, whatever version
the server runs, so a statement the version does not run comes back refused by
the server, as a `QueryException`. Each `?` outside a string literal or a
quoted identifier is filled with the next binding, escaped as the builder
escapes its values. A regular expression literal you write out is not
recognised, though: a `?` inside one, as in `/^web-?1/`, is taken for a
placeholder, so pass such a pattern as a `Regex` binding instead. A binding
can be a string, a number, a boolean, a date, a `Regex`, a `Stringable`, or an
`Expression`, which is embedded as written. `null`, `NAN`, `INF`, an array, an
enum or any other object throws an `InvalidArgumentException` before anything
is sent: unlike the builder, the connection does not turn an enum into its
value or name.

Statements are sent as a `POST` to `/query` through the InfluxDB client's
transport, so the connection's token, `timeout`, `verifySSL`, `proxy`, `debug`
and `httpClient` apply to them as they do to writes: see
[Passing options to the client](configuration.md#passing-options-to-the-client).
The statement travels in the request body, so its length is not bound by the
URL.

### When a statement fails

A statement the server refuses, or one that never reaches it, throws an
`Ipsocode\InfluxDB\InfluxQL\QueryException`, whose message names the
connection and carries the statement exactly as it was sent:
`<error> (Connection: main, InfluxQL: SELECT ...)`. `getConnectionName()` and
`getSql()` return those parts, `getBindings()` the values before they were
embedded, `getError()` the server's or the transport's message alone, and
`getPrevious()` the transport's exception, when there is one. When several
statements are sent together, the exception carries the error of the first
one that failed.

## What carries over from Hypervel's builder

The methods, and the way they combine, are Hypervel's: `select`, `addSelect`,
`selectRaw`, `distinct`, `from`, `fromRaw`, `fromSub`, `where` and `orWhere`
with closures and arrays, `whereColumn`, `whereRaw`, `whereIn`, `whereNotIn`,
`whereBetween` and `whereNotBetween` (a `CarbonPeriod` included), with their
`orWhere...` forms, dynamic wheres such as `whereHost('web1')`, `groupBy`,
`groupByRaw`, `orderBy`, `orderByDesc`, `latest`, `oldest`, `orderByRaw`,
`reorder`, `limit`, `offset`, `take`, `skip`, `forPage`, `get`, `first`,
`value`, `pluck`, `exists`, `doesntExist`, `existsOr`, `doesntExistOr`,
`count`, `min`, `max`, `sum`, `avg`, `average`, `aggregate`,
`numericAggregate`, `chunk`, `raw`, `clone`, `when`, `unless`, `tap` and
macros.

A few of them read differently, because InfluxQL does:

- `avg()` is InfluxQL's `MEAN()`, and `aggregate()` takes any InfluxQL
  aggregate or selector, such as
  `aggregate('percentile', ['usage_user', new Expression('95')])`. InfluxDB 3
  has no `SAMPLE()`, `HOLT_WINTERS()` or technical-analysis functions, such as
  `CHANDE_MOMENTUM_OSCILLATOR()`, and refuses them; the builder does not check
  function names, so the refusal comes from the server.
- `count()` with no column counts every field and returns the first field's
  count, so name the field to count: `count('usage_user')`. An aggregate over
  no points is `null`, except for `count()`, `sum()` and `numericAggregate()`,
  which return 0.
- `whereIn()` compiles to one equality per value joined by `OR`, and
  `whereBetween()` to a `>=` and a `<=`, since InfluxQL has neither `IN` nor
  `BETWEEN`. `whereNotBetween()` excludes both bounds. An empty `whereIn()`
  compiles to `0 = 1`, which matches nothing, and an empty `whereNotIn()` to
  `1 = 1`, which matches everything, as in Hypervel.
- A dotted `from()` is InfluxQL's qualified measurement,
  `database.retention_policy.measurement` or `retention_policy.measurement`,
  and `database..measurement` reads the database's default retention policy.
  A column is never split on dots. A measurement whose own name holds a dot is
  passed as an `Expression`, quoted yourself: `new Expression('"cpu.total"')`.
- `from()` and `fromSub()` take a closure or another builder as a subquery. A
  measurement takes no alias, since InfluxQL has none.
- `orderBy()` sorts by `time` only; any other column, unless given as an
  `Expression`, throws an `InvalidArgumentException`.
- `distinct()` wraps InfluxQL's `DISTINCT()` function around each selected
  column.
- `value()` and `pluck()` given an `Expression`, such as an unaliased function,
  read the first column after `time`, under whatever name the server gave it.
  A tag can be plucked alongside a field, but not on its own: InfluxQL returns
  no rows for a query that selects tags only.
- `chunk()` pages with `LIMIT` and `OFFSET` without adding an order, since
  InfluxQL returns points in time order anyway.
- A dynamic where reads its columns in snake case, joined by `And` or `Or`:
  `whereHostAndRegion('web1', 'eu')` compares `host` and `region`.

## What InfluxQL adds

```php
use Ipsocode\InfluxDB\InfluxQL\Regex;

InfluxDB::table('cpu')
    ->selectRaw('MEAN("usage_user") AS "mean"')
    ->where('host', '=~', '^web')            // or ->where('host', new Regex('^web'))
    ->where('time', '>=', now()->subDay())
    ->groupByTime('1h')                      // groupByTime('1h', '-15m') adds an offset
    ->groupBy('region')
    ->fill('previous')                       // none, null, previous, linear or a number
    ->tz('Europe/Amsterdam')
    ->get();
```

- `groupByTime()` with `fill()`, `slimit()` and `soffset()` to limit and skip
  series, and `tz()` for the time zone of the returned timestamps. InfluxDB 3
  has no `SLIMIT` or `SOFFSET`, so a `v3` connection refuses any value but 0,
  which it leaves out.
- The interval and offset of `groupByTime()` must be InfluxQL duration
  literals, such as `10m`, `1h` or `-15m`, since they are embedded as written;
  `fill()` takes a number or one of its four keywords, and `tz()` a time zone
  name PHP knows. Anything else throws an `InvalidArgumentException`.
- `groupBy()` takes tags, or `*` for every tag; a time window goes through
  `groupByTime()`. A function is selected with `selectRaw()`, since a function
  call is not a column name.
- Regular expressions: a string compared with `=~` or `!~`, or a `Regex`
  value, which turns `=` and `!=` into `=~` and `!~`.
  `from(new Regex('^cpu'))` queries every measurement that matches. A pattern
  is given without its `/` delimiters, and a `/` inside it is escaped for you.
  `=~` and `!~` take a regular expression only, and a `Regex` takes no
  operator but `=`, `!=`, `<>`, `=~` and `!~`: either mistake throws an
  `InvalidArgumentException`.
- A column may carry InfluxQL's type hint, such as `host::tag`,
  `usage_user::field` or a cast such as `::integer`, which stays outside the
  quotes; the row reports the column under its name alone. An alias reads as
  in Hypervel: `select('usage_user as user')`.
- `into('database.retention_policy.measurement')` writes the result of the
  query to another measurement, as `SELECT ... INTO`. InfluxDB 2.x and 3 have
  no `SELECT ... INTO`, so `v2` and `v3` connections refuse it.
- `delete()` removes the points the where clause selects: see
  [Deleting points](#deleting-points).

## What InfluxQL lacks

InfluxQL has no joins, havings, unions, inserts or updates (points are written
through [`writeApi()`](writing.md#writing-through-writeapi)), and no null. The
builder refuses what InfluxQL cannot express, rather than compile it to
something else:

- A `null` value, and an SQL operator such as `like`, throw an
  `InvalidArgumentException`. Match a regular expression with `=~` instead.
  A comparison with a subquery throws one too: a closure given as the column
  starts a nested where, and takes no operator or value.
- `whereNull()`, `whereNot()`, `whereLike()`, the date-part clauses
  (`whereDate()`, `whereYear()`, ...), the relative-date clauses
  (`wherePast()`, `whereToday()`, ...), and the sub-select, JSON and full-text
  clauses throw a `BadMethodCallException`. For a relative date, compare
  `time` directly: `where('time', '<', now())`.

InfluxDB answers an `OR` between time ranges with no points, so keep `time`
out of `whereIn()`, `whereNotBetween()` and `orWhere()`. They work as expected
on fields and tags.

A statement the server refuses throws a `QueryException`: see
[Running statements](#running-statements).

## Deleting points

`delete()` removes the points the where clause selects, as
`DELETE FROM <measurement> WHERE ...`, and returns true once the server
accepts the statement; InfluxDB reports no count.

```php
InfluxDB::table('cpu')
    ->where('host', 'web1')
    ->where('time', '<', now()->subDays(30))
    ->delete();
```

It takes conditions on time and tags only, and the server refuses one on a
field. Without a time condition it deletes from all time, future points
included: InfluxDB 1.8 and 2.7 do, although InfluxData's documentation says
such a `DELETE` stops at `now()`. The measurement cannot name a database or
retention policy, which InfluxDB refuses in a `DELETE`: a dotted measurement,
or none at all, throws an `InvalidArgumentException` before anything is sent.
A measurement whose own name holds a dot is passed as an `Expression`.

InfluxQL's `DELETE` takes no `LIMIT`, `OFFSET`, `SLIMIT`, `SOFFSET` or
`ORDER BY`, and the server refuses a statement that has one. `delete()`
leaves out the query's `limit()`, `offset()`, `slimit()`, `soffset()` and
order, so it deletes every point the where clause matches: narrow it with
`where()`.

Which retention policies the points go from depends on the version:

- On InfluxDB 1.x it deletes from every retention policy of the database, not
  only the connection's.
- On InfluxDB 2.x it deletes from the database's default retention policy
  only, so a `v2` connection refuses it when it addresses a retention policy,
  as below.
- InfluxDB 3 has no `DELETE`, so a `v3` connection refuses it: delete the
  table or the database instead. InfluxDB 3 Enterprise 3.11 and later can
  also delete rows by time range and tag, outside InfluxQL, with
  [`influxdb3 delete rows`](https://docs.influxdata.com/influxdb3/enterprise/admin/delete-data/).

A refusal is a `RuntimeException`, thrown as the statement compiles, before
anything is sent.

### On InfluxDB 2.x

InfluxDB 2.x runs a `DELETE` on the database's default retention policy,
whichever one the request names, and a virtual DBRP mapping is its database's
default only for a bucket named without a slash. So `delete()` runs on a
connection that addresses a database alone, such as one whose bucket is
`telegraf`, and deletes from the bucket that connection reads. On a connection
that addresses a retention policy, through a `db/rp` bucket or
`retentionPolicy`, it throws a `RuntimeException` instead: 2.x would delete
from another bucket, or refuse the statement when the database has no default
mapping. If that policy is its database's default (a mapping created with
`--default`), set `database` alone to delete through InfluxQL. Otherwise,
delete through the `/api/v2/delete` API, which the InfluxDB client provides:

```php
use InfluxDB2\Model\DeletePredicateRequest;
use InfluxDB2\Service\DeleteService;

InfluxDB::createService(DeleteService::class)->postDelete(
    new DeletePredicateRequest([
        'start' => new DateTime('-1 day'),
        'stop' => new DateTime(),
        'predicate' => '_measurement="cpu" AND host="web1"',
    ]),
    org: 'my-org',
    bucket: 'telegraf/weekly',
);
```

[Connecting to InfluxDB 2.x](configuration.md#connecting-to-influxdb-2x)
explains the DBRP mappings.

## The builder checks the server's version

The builder compiles for the version the connection names, and a statement
that version cannot run, such as `into()` on a `v2` or `v3` connection, throws
a `RuntimeException` as it compiles, before anything is sent: the table in
[Choosing the server version](configuration.md#choosing-the-server-version)
lists what each version refuses. It also refuses a server that runs another
major version than the connection names, or does not say which version it
runs, with a `QueryException`, again before anything is sent. Statements you
write out and run on `InfluxDB::influxql()` are not checked.

A server names its version in the `X-Influxdb-Version` header of its `/ping`
answer, and only the major version is compared.
`InfluxDB::influxql()->getServerVersion()` returns what the server reported,
sending a `/ping` if it is not yet known, or `null` when the server names
none.

Each server's version is read once, when the Hypervel server starts, not by
each worker or statement. Before the server forks its workers, the package
sends one `/ping` to every configured connection's server and keeps the
answers in the non-coroutine context, which every worker inherits. A
connection that is not fully configured, or whose server cannot be reached or
names no version, is left out, and each worker's builder asks that server
before its first statement instead. Once the server names a version, the
worker keeps it; a server that names none is asked again before the next
statement. A server that does not answer holds the start for up to the
connection's `timeout`, 10 seconds by default.
[Server version detection](internals.md#server-version-detection) explains
how.

A value InfluxQL has no literal for throws its `InvalidArgumentException`
before anything is sent, that `/ping` included. A `/ping` that fails throws a
`QueryException` that carries the statement it held back, with the
transport's exception as its previous one.

## Configuring InfluxQL

Each connection can carry an `influxql` block:

```php
'connections' => [
    'main' => [
        // version, url, token, bucket, org, ...
        'influxql' => [
            'database' => env('INFLUXDB_DATABASE'),
            'retentionPolicy' => env('INFLUXDB_RETENTION_POLICY'),
            'epoch' => env('INFLUXDB_EPOCH'),
        ],
    ],
],
```

| Key | Default | Meaning |
|---|---|---|
| `database` | The bucket, up to its first `/`; on `v3`, the whole bucket | The database the statements are addressed to. |
| `retentionPolicy` | The bucket after its first `/`, or else the database's default; on `v3`, none | The retention policy the statements are addressed to. A `v3` connection refuses one, since InfluxDB 3 has no retention policies. |
| `epoch` | None | Return timestamps as integers of this precision (`ns`, `u`, `ms`, `s`, `m` or `h`, and on `v3` also `d` or `w`) instead of RFC3339 strings. `µ` is accepted and sent as `u`, the one spelling every version reads. |

InfluxQL addresses data by database and retention policy rather than by
bucket. Without a `database`, both come from the bucket, split at its first
slash: a bucket named `telegraf/autogen` is database `telegraf` on retention
policy `autogen`, and one named `telegraf` is database `telegraf` on its
default policy. That is how InfluxDB 1.x's 2.x-compatibility API and 2.x's
virtual DBRP mappings read a bucket name. A `retentionPolicy` in the block
wins over the bucket's. With a `database`, the bucket is not read: the
statements go to that database, on the block's `retentionPolicy` or else the
database's default. InfluxDB 3 has no retention policies, and stores what the
2.x API writes under the whole bucket name, so on a `v3` connection the bucket
is the database, unsplit.

An `epoch` outside the list, or a `retentionPolicy` on a `v3` connection,
throws an `InvalidArgumentException` naming the connection when its InfluxQL
connection is built. `getDatabase()`, `getRetentionPolicy()` and `getEpoch()`
on `InfluxDB::influxql()` show what the statements are sent with, as the
`db`, `rp` and `epoch` parameters of each request.

What each server needs on its side is in
[Connecting to InfluxDB 1.x](configuration.md#connecting-to-influxdb-1x),
[Connecting to InfluxDB 2.x](configuration.md#connecting-to-influxdb-2x) and
[Connecting to InfluxDB 3](configuration.md#connecting-to-influxdb-3).
