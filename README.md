# hypervel-influxdb

InfluxDB connections for [Hypervel](https://github.com/hypervel/components):
a connection manager, a facade and a publishable config on top of the official
[`influxdata/influxdb-client-php`](https://github.com/influxdata/influxdb-client-php),
an InfluxQL query builder for InfluxDB 1.x, 2.x and 3, database drivers that
run Hypervel's own query builder on InfluxQL, for any of them, and on SQL, for
InfluxDB 3, and a read-only Eloquent model of a measurement for either driver.
Originally ported from [`ge-tracker/influxdb-laravel`](https://github.com/ge-tracker/influxdb-laravel).

> [!WARNING]
> **Development only — do not use this package in production until Hypervel 0.4
> is released.**
>
> It is built for Hypervel 0.4, which has no release yet: 0.4 exists only as the
> `0.4.x-dev` branch of [`hypervel/components`](https://github.com/hypervel/components),
> and this package is developed and tested against that moving branch. Until 0.4
> ships, anything here can change without a deprecation period — the API and the
> configuration included. Use it to evaluate or to build against Hypervel 0.4,
> and pin the version you tested.

```php
use InfluxDB2\Point;
use Ipsocode\InfluxDB\Facades\InfluxDB;

InfluxDB::writeApi()->write(
    Point::measurement('cpu')->addTag('host', 'web1')->addField('load', 0.64)
);
```

## What this is

A thin layer that turns `config/influxdb.php` into named, lazily created
`InfluxDB2\Client` instances, and hands them out through a manager, a facade
and the container — the same shape as upstream's package, so its mental model
carries over. Most of its README's examples do not: they were written for the
legacy `influxdb/influxdb-php` client, and `InfluxDB2\Client` has none of their
methods, such as `writePoints()` or `getQueryBuilder()`. Writing, Flux queries
and the management services are the official `influxdata/influxdb-client-php`
unchanged. On top of that, the package adds a
[batching writer](#batching-writes) built for long-lived workers, an
[InfluxQL query builder](#querying-with-influxql) shaped like Hypervel's own
`Query\Builder`, and two database drivers that run Hypervel's query builder
itself: `influxql`, [over InfluxQL](#hypervels-query-builder-over-influxql) on
any version, and `influxdb`, which gives InfluxDB 3
[the same over SQL](#querying-influxdb-3-with-sql). On either driver,
[`Measurement`](#models-of-measurements) is a read-only Eloquent model with
the settings a time series needs.

Upstream builds its manager on `graham-campbell/manager`, which is Laravel
only. Here the manager is implemented natively on Hypervel's
`Hypervel\Contracts\*` and `Hypervel\Support\*`, so the package pulls in no
Illuminate components.
[Differences from `ge-tracker/influxdb-laravel`](#differences-from-ge-trackerinfluxdb-laravel)
lists what else changed.

## Requirements

- PHP 8.4 or newer (CI runs 8.4 and 8.5)
- Hypervel 0.4, which today means `hypervel/components` at `0.4.x-dev`. The
  package requires `hypervel/collections`, `hypervel/conditionable`,
  `hypervel/console`, `hypervel/container`, `hypervel/context`,
  `hypervel/contracts`, `hypervel/coordinator`, `hypervel/core`,
  `hypervel/coroutine`, `hypervel/database`, `hypervel/foundation`,
  `hypervel/macroable` and `hypervel/support` `^0.4`; `hypervel/components`
  provides them all.
- `influxdata/influxdb-client-php` `^3.9`, and a server it can talk to:
  InfluxDB 2.x, or InfluxDB 1.8+ or InfluxDB 3 Core or Enterprise through
  their 2.x compatibility API. The
  [InfluxQL query builder](#querying-with-influxql) and the
  [`influxql` database driver](#hypervels-query-builder-over-influxql) run on
  any of them, compiled for the version the connection
  [names](#choosing-the-server-version), and
  [SQL](#querying-influxdb-3-with-sql) runs on InfluxDB 3.

## Installation

```sh
composer require ipsocode/hypervel-influxdb
php artisan vendor:publish --tag=influxdb-config
```

Hypervel 0.4 is only available as a dev branch, so your application's
`composer.json` must already allow it: `"minimum-stability": "dev"` together
with `"prefer-stable": true`. Tags are not re-tested as `0.4.x-dev` moves on,
and neither is `main` between changes: each change is tested against the
`0.4.x-dev` of its day before it merges. To pick up changes as they land,
require `ipsocode/hypervel-influxdb:dev-main` instead. Each release's notes,
breaking changes first, are on the
[Releases](https://github.com/ipsocode/hypervel-influxdb/releases) page.

The service provider (`Ipsocode\InfluxDB\InfluxDBServiceProvider`) and the
`InfluxDB` alias are discovered through the package's `extra.hypervel` block,
so there is nothing to register. Publishing the config is optional: without it
the package's own `config/influxdb.php` applies, driven by the environment
variables below.

## Configuration

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
the key when it is first resolved. `verifySSL` defaults to `true`; set
`INFLUXDB_VERIFY_SSL=false` only for a server with a certificate you cannot
verify, such as a self-signed one in development.

More connections go under `connections` in `config/influxdb.php`, and
`INFLUXDB_CONNECTION` picks the default. The provider merges your file with the
package's by connection name: an entry you define replaces the package's entry
of the same name, and the package's `main` stays available alongside your own
connections unless you redefine it.

### Choosing the server version

`version` names the major version of InfluxDB the connection's server runs:
`v1`, the default, `v2` or `v3`. Writes and Flux queries do not depend on it,
since the upstream client speaks the 2.x API, which InfluxDB 1.8 and later and
InfluxDB 3 serve for compatibility (InfluxDB 3 serves writes, not Flux).
[InfluxQL queries](#querying-with-influxql) do: the builder, and the
[`influxql` database driver](#hypervels-query-builder-over-influxql), compile
for the version, and check it against the version the server reports before
they send their first statement. [SQL](#querying-influxdb-3-with-sql) needs
`v3`.

| | `v1`: InfluxDB 1.8 or later | `v2`: InfluxDB 2.x | `v3`: InfluxDB 3 Core or Enterprise |
|---|---|---|---|
| `token` | `username:password`, or any non-empty value when authentication is off | An API token | A token, or any non-empty value when authentication is off |
| `org` | Required by the client, ignored by the server: `-` will do | The organization | Required by the client, ignored by the server |
| `bucket` | `database/retention-policy`, or `database` on its default policy | Any bucket, which InfluxQL reaches through a [DBRP mapping](#connecting-to-influxdb-2x) | The database, named whole: a slash is part of the name |
| `into()` | Runs `SELECT ... INTO` | Refused: 2.x has no `SELECT ... INTO`, so downsample with a task | Refused: 3 has no `SELECT ... INTO` |
| `slimit()`, `soffset()` | Run | Run | Refused, unless 0 |
| `delete()` | Deletes from every retention policy of the database | Deletes from the database's default retention policy only, so it is refused on a connection that addresses a retention policy | Refused: 3 has no `DELETE` |
| `epoch` | `ns`, `u`, `ms`, `s`, `m` or `h` | The same | Those, `d` and `w` |
| Hypervel's query builder over InfluxQL | Through the [`influxql` database driver](#hypervels-query-builder-over-influxql), with the refusals above | The same | The same |
| SQL | Not available | Not available | Through the [`influxdb` database driver](#querying-influxdb-3-with-sql) |

### Passing options to the client

A connection's config is passed to `InfluxDB2\Client` whole, so any option the
client accepts can be set on it, not only the keys shown above — `timeout`,
`proxy`, `allow_redirects`, `tags` (default tags for every point), `logFile`,
`httpClient` and the rest. Three keys are this package's own: `write`, the
write options `writeApi()` passes to the connection's `WriteApi`, which also
turn on [batching](#batching-writes), and `version` and `influxql`, which
configure [InfluxQL queries](#querying-with-influxql) and are not passed to
the client.

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

`timeout`, `proxy` and `verifySSL` configure the Guzzle client the upstream
client builds for itself. With `httpClient` set to a PSR-18 client of your own,
they are not applied, so configure that client instead. `allow_redirects`,
`debug` and the token apply to either.

## Usage

Through the facade, which proxies to the default connection:

```php
use InfluxDB2\Point;
use Ipsocode\InfluxDB\Facades\InfluxDB;

$writeApi = InfluxDB::writeApi();              // the default connection's
$writeApi->write(Point::measurement('cpu')->addField('load', 0.64));

InfluxDB::writeApi('analytics')->write($points); // a named connection's

$tables = InfluxDB::createQueryApi()->query(
    'from(bucket: "my-bucket") |> range(start: -1h)'
);
```

Write through `writeApi()`. It keeps one `WriteApi` per connection for the
life of the worker. The upstream client's own `createWriteApi()` is still
reachable through the facade and the manager, but the client keeps a
reference to every `WriteApi` it creates, so calling it on each request leaks
one per request until the worker exits. `write()` also takes an array of
points, which a synchronous `WriteApi` sends in one request. To buffer points
and send them in batches instead, see [Batching writes](#batching-writes).

Through the container:

```php
use InfluxDB2\Client;
use Ipsocode\InfluxDB\InfluxDBManager;

public function __construct(
    private InfluxDBManager $influx,   // the manager
    private Client $client,            // the default connection
) {}

public function handle(): void
{
    $analytics = $this->influx->connection('analytics');
}
```

The container bindings are upstream's: `influxdb` (the manager, aliased to
`InfluxDBManager`), `influxdb.factory` (aliased to `InfluxDBFactory`) and
`influxdb.connection` (the default connection, aliased to `InfluxDB2\Client`).

### Reading points back with `RefPoint`

`InfluxDB2\Point` keeps its measurement, tags and fields private, with no
getters. `RefPoint` reads them back through reflection, which is handy for
inspecting points you have built, or asserting on them in tests:

```php
use InfluxDB2\Point;
use Ipsocode\InfluxDB\Support\RefPoint;

$ref = RefPoint::from(Point::measurement('cpu')->addTag('host', 'web1')->addField('load', 0.64));

$ref->getMeasurement(); // 'cpu'
$ref->getTags();        // ['host' => 'web1']
$ref->getFields();      // ['load' => 0.64]
```

`RefPoint::from()` also accepts an array of points, and returns an array of
`RefPoint`s.

## Batching writes

By default each `write()` is one request, sent before `write()` returns.
InfluxData recommends writing in batches instead: fewer, larger requests. A
connection whose `writeType` is `WriteType::BATCHING`, upstream's own switch,
buffers its points in the worker and sends them in batches:

```php
use InfluxDB2\WriteType;

'connections' => [
    'main' => [
        // url, token, bucket, org, ...
        'write' => [
            'writeType' => WriteType::BATCHING,
            'batchSize' => 5000,   // lines of line protocol per request
            'batchSizeMb' => 10,   // megabytes per request
            'flushInterval' => 1,  // seconds a batch waits to fill
        ],
    ],
],
```

The published config turns it on for `main` with `INFLUXDB_BATCHING=true`, and
reads the limits from `INFLUXDB_BATCH_SIZE`, `INFLUXDB_BATCH_SIZE_MB` and
`INFLUXDB_FLUSH_INTERVAL`. `writeApi()` then returns an `Ipsocode\InfluxDB\Write\BatchingWriter`: a
`WriteApi` whose `write()` and `writeRaw()` buffer rather than send, so code
written against a synchronous one carries over. It serialises each point as a
synchronous `WriteApi` would, default `tags`, precision, bucket and org
included. It replaces upstream's batching `WriteApi`, which sends a batch only
once `batchSize` writes have queued, loses what is queued when the worker
stops, and throws a failed batch at whichever write filled it.

### When a batch is sent

Points are grouped by bucket, org and precision, a batch for each, since a
request writes to one of each. A batch is sent:

- once it holds `batchSize` lines or `batchSizeMb` megabytes, whichever comes
  first, from a coroutine of its own: the request or job whose write filled
  it waits neither for it nor for its retries. A write that would take a
  batch past either limit starts the next one, so no request outgrows them,
  unless a single write does: a list of points is split between batches, but
  a string of line protocol is kept whole;
- `flushInterval` seconds after the buffer took its first point, at the
  latest;
- when `InfluxDB::flush()` is called, or `flush('analytics')` for another
  connection, or `flushAll()` for every one, which sends the buffer from the
  calling coroutine and waits for the batches already being sent;
- when the worker exits: the flush timer waits on Hypervel's worker-exit
  coordinator, which wakes it early;
- when a console command, a queue worker included, finishes, and when the
  console application terminates;
- when the connection is disconnected.

Left unset, `batchSize` and `batchSizeMb` follow InfluxData's advice for the
connection's [`version`](#choosing-the-server-version):

| `version` | `batchSize` | `batchSizeMb` | From InfluxData's docs |
|---|---|---|---|
| `v1` | 5,000 lines | 25 | 1.x recommends [5,000 to 10,000 points per batch](https://docs.influxdata.com/influxdb/v1/concepts/glossary/#batch), and refuses a request body over [25 MB](https://docs.influxdata.com/influxdb/v1/administration/config/#max-body-size) by default |
| `v2` | 5,000 lines | 50 | [5,000 lines is the optimal batch size](https://docs.influxdata.com/influxdb/v2/write-data/best-practices/optimize-writes/#batch-writes) on 2.x; [InfluxDB Cloud](https://docs.influxdata.com/influxdb/cloud/account-management/limits/) refuses a request over 50 MB, and OSS 2.x sets no limit |
| `v3` | 10,000 lines | 10 | [10,000 lines or 10 MB, whichever threshold is met first](https://docs.influxdata.com/influxdb3/core/write-data/best-practices/optimize-writes/#batch-writes) |

A megabyte is 1,000,000 bytes, which keeps `batchSizeMb` under a server's
limit whichever way the server counts. InfluxData's docs set no time limit;
`flushInterval` defaults to 1 second on every version. Numbers may be given as
the strings `env()` reads, and an option out of range throws an
`InvalidArgumentException` naming the connection and the option when
`writeApi()` first builds the writer.

### When a batch cannot be written

A batch is retried as a synchronous write is, on a status of `429` or above or
a network error, with the client's backoff, except that `maxRetries` defaults
to 3 instead of upstream's 5. A batch that still fails is dropped, and never
thrown at a `write()`, since the write that filled it carries mostly other
writes' points. Instead it is:

- reported through the application's exception handler as an
  `Ipsocode\InfluxDB\Write\BatchWriteException`, which names the connection,
  the batch's size and bucket, and the server's error, and whose previous
  exception is the client's `ApiException`;
- handed to `onFailure`, when the connection sets one, with that exception.
  The `Batch` carries its `payload` of line protocol and the `bucket`, `org`
  and `precision` it was going to, so it can be written again later.

```php
'write' => [
    'writeType' => WriteType::BATCHING,
    'onFailure' => App\Metrics\SpillFailedBatch::class, // or any callable
],
```

```php
namespace App\Metrics;

use Ipsocode\InfluxDB\Write\Batch;
use Ipsocode\InfluxDB\Write\BatchWriteException;

class SpillFailedBatch
{
    public function __invoke(Batch $batch, BatchWriteException $exception): void
    {
        // Keep $batch->payload somewhere durable, to write to $batch->bucket later.
    }
}
```

A class name is resolved from the container, and keeps the config cacheable,
which a closure does not. A batch the server refuses may still have been
written in part: InfluxDB 1.x and 3 write the lines they accept and refuse the
rest with a `400`, and 2.x refuses conflicting points with a `422` and writes
the rest, but refuses a whole batch with a `400` when a line cannot be parsed.
The server's message, in the exception, describes what it refused.

### How much a worker holds

`maxBuffered` caps the points a writer holds, buffered or being sent, at ten
batches' worth by default. A write that would take it past the cap sends the
buffer itself before it returns, so while the server falls behind, writes slow
to its pace as synchronous ones would. With `'overflow' => 'refuse'`, such a
write throws an `Ipsocode\InfluxDB\Write\BufferFullException` instead, and
keeps none of its points. The writer's `getPendingPoints()` counts what it
holds.

### What batching does not solve

Points wait in one worker's memory until they are sent. A graceful exit sends
them, within the `max_wait_time` Hypervel gives a stopping worker, 3 seconds by
default, but a crash, an out-of-memory kill or a `SIGKILL` loses them. Batch
data that can bear the loss, such as metrics, and keep writing what cannot
synchronously, or from a queued job. Code that writes outside a coroutine,
such as a task worker without `task_enable_coroutine`, has no flush timer:
its buffer is sent by the first write after `flushInterval`, so call `flush()`
when that work is done.

## Querying with InfluxQL

Points can also be read back with InfluxQL, through a query builder shaped
like Hypervel's `Query\Builder`. It sends InfluxQL to the `/query` endpoint
that InfluxDB 1.x defines and 2.x and 3 also serve, compiled for the
connection's [`version`](#choosing-the-server-version):

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
of its series appended. `series()` returns the series themselves instead, each
with its `name`, `tags`, `columns` and `values`. `toSql()` shows the statement
with `?` placeholders, and `toRawSql()` shows it as it is sent, with the values
escaped and embedded.

The builders run on one InfluxQL connection per configured connection,
`InfluxDB::influxql($name)`, which also runs statements you write out:

```php
$rows = InfluxDB::influxql()->select(
    'SELECT MEAN("usage_user") FROM "cpu" WHERE "host" = ? GROUP BY time(1h)',
    ['web1'],
);
```

`select()` returns the rows of the first statement and `selectOne()` its first
row, `results()` returns one `Result` per `;`-separated statement, and
`statement()` returns true once the server accepts a statement whose result is
not needed. InfluxDB 2.x returns no `Result` for a `DELETE` or a
`DROP MEASUREMENT` that succeeds. Unlike the builder's statements, these are
sent as written, whatever version the server runs, so a statement the version
does not run comes back refused by the server, as a `QueryException`.

### What carries over from Hypervel's builder

The methods, and the way they combine, are Hypervel's: `select`, `addSelect`,
`selectRaw`, `distinct`, `from`, `fromRaw`, `fromSub`, `where` and `orWhere`
with closures and arrays, `whereColumn`, `whereRaw`, `whereIn`, `whereNotIn`,
`whereBetween` and `whereNotBetween` (a `CarbonPeriod` included), dynamic wheres
such as `whereHost('web1')`, `groupBy`, `groupByRaw`, `orderBy`,
`orderByDesc`, `latest`, `oldest`, `orderByRaw`, `reorder`, `limit`,
`offset`, `take`, `skip`, `forPage`, `get`, `first`, `value`, `pluck`,
`exists`, `doesntExist`, `existsOr`, `doesntExistOr`, `count`, `min`, `max`,
`sum`, `avg`, `aggregate`, `numericAggregate`, `chunk`, `when`, `unless`,
`tap` and macros.

A few of them read differently, because InfluxQL does:

- `avg()` is InfluxQL's `MEAN()`, and `aggregate()` takes any InfluxQL
  aggregate or selector, such as
  `aggregate('percentile', ['usage_user', new Expression('95')])`. InfluxDB 3
  has no `SAMPLE()`, `HOLT_WINTERS()` or technical-analysis functions, such as
  `CHANDE_MOMENTUM_OSCILLATOR()`, and refuses them.
- `count()` with no column counts every field and returns the first field's
  count, so name the field to count: `count('usage_user')`. An aggregate over
  no points is `null`, except for `count()` and `sum()`, which return 0.
- `whereIn()` compiles to one equality per value joined by `OR`, and
  `whereBetween()` to a `>=` and a `<=`, since InfluxQL has neither `IN` nor
  `BETWEEN`.
- A dotted `from()` is InfluxQL's qualified measurement,
  `database.retention_policy.measurement`. A column is never split on dots.
- `orderBy()` sorts by `time` only.

### What InfluxQL adds

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
- Regular expressions: a string compared with `=~` or `!~`, or a `Regex`
  value, which turns `=` and `!=` into `=~` and `!~`.
  `from(new Regex('^cpu'))` queries every measurement that matches.
- `into('database.retention_policy.measurement')` writes the result of the
  query to another measurement, as `SELECT ... INTO`. InfluxDB 2.x and 3 have
  no `SELECT ... INTO`, so `v2` and `v3` connections refuse it.
- `delete()` removes the points the where clause selects, as
  `DELETE FROM <measurement> WHERE ...`. It takes conditions on time and tags
  only, and a measurement without a database or retention policy, which
  InfluxDB refuses in a `DELETE`. Without a time condition it deletes from all
  time, future points included. On InfluxDB 1.x it deletes from every
  retention policy of the database, not only the connection's. On 2.x it
  deletes from the database's default retention policy only, so a `v2`
  connection refuses it when it addresses a retention policy: see
  [Connecting to InfluxDB 2.x](#connecting-to-influxdb-2x). InfluxDB 3 has no
  `DELETE`, so a `v3` connection refuses it: delete the table or the database
  instead.

### What InfluxQL lacks

InfluxQL has no joins, havings, unions, inserts or updates (points are written
through `writeApi()`), and no null. The builder refuses what InfluxQL cannot
express, rather than compile it to something else:

- A `null` value, and an SQL operator such as `like`, throw an
  `InvalidArgumentException`. Match a regular expression with `=~` instead.
- `whereNull()`, `whereNot()`, `whereLike()`, the date-part clauses
  (`whereDate()`, `whereYear()`, ...), the relative-date clauses
  (`wherePast()`, `whereToday()`, ...), and the sub-select, JSON and full-text
  clauses throw a `BadMethodCallException`. For a relative date, compare
  `time` directly: `where('time', '<', now())`.

InfluxDB answers an `OR` between time ranges with no points, so keep `time`
out of `whereIn()`, `whereNotBetween()` and `orWhere()`. They work as expected
on fields and tags.

A statement the server refuses, or one that never reaches it, throws an
`Ipsocode\InfluxDB\InfluxQL\QueryException`, whose message names the
connection and carries the statement exactly as it was sent.

### The builder checks the server's version

The builder compiles for the version the connection names, and a statement
that version cannot run, such as `into()` on a `v2` or `v3` connection, throws
a `RuntimeException` as it compiles, before anything is sent. It also refuses a
server that runs another major version than the connection names, or does not
say which version it runs, with a `QueryException`, again before anything is
sent. Statements you write out and run on `InfluxDB::influxql()` are not
checked.

Each server's version is read once, when the Hypervel server starts, not by
each worker or statement. Before the server forks its workers, the package
sends one `/ping` to every configured connection's server and keeps the
answers in the non-coroutine context, which every worker inherits. A
connection that is not fully configured, or whose server cannot be reached or
names no version, is left out, and each worker's builder asks that server
before its first statement instead. A server that does not answer holds the
start for up to the connection's `timeout`, 10 seconds by default.

### Configuring InfluxQL

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
virtual DBRP mappings read a bucket name. InfluxDB 3 has no retention
policies, and stores what the 2.x API writes under the whole bucket name, so
on a `v3` connection the bucket is the database, unsplit: see
[Connecting to InfluxDB 3](#connecting-to-influxdb-3).

#### Connecting to InfluxDB 1.x

The upstream client speaks the 2.x API, which InfluxDB 1.8 and later serve for
compatibility. A connection to a 1.x server looks like this:

```dotenv
INFLUXDB_VERSION=v1
INFLUXDB_URL=http://localhost:8086
INFLUXDB_TOKEN=username:password   # any non-empty value when auth is disabled
INFLUXDB_BUCKET=telegraf/autogen   # database/retention-policy
INFLUXDB_ORG=-                     # required by the client, ignored by 1.x
```

Writes then go to that database and retention policy, and InfluxQL queries
read from them.

#### Connecting to InfluxDB 2.x

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
derived from its name as above, so InfluxQL reads the connection's bucket with
no setup. To address a bucket by another database name, create a mapping on
the server (`influx v1 dbrp create`), and set that mapping's database as
`database`.

`delete()` needs more care. InfluxDB 2.x runs a `DELETE` on the database's
default retention policy, whichever one the request names, and a virtual
mapping is its database's default only for a bucket named without a slash. So
`delete()` runs on a connection that addresses a database alone, such as one
whose bucket is `telegraf`, and deletes from the bucket that connection reads.
On a connection that addresses a retention policy, through a `db/rp` bucket or
`retentionPolicy`, it throws a `RuntimeException` instead: 2.x would delete
from another bucket, or refuse the statement when the database has no default
mapping. If that policy is its database's default (a mapping created with
`--default`), set `database` alone to delete through InfluxQL. Otherwise,
delete through the `/api/v2/delete` API, which the upstream client provides:

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

#### Connecting to InfluxDB 3

```dotenv
INFLUXDB_VERSION=v3
INFLUXDB_URL=http://localhost:8181
INFLUXDB_TOKEN=apiv3_...           # any non-empty value when auth is disabled
INFLUXDB_BUCKET=telegraf           # the database
INFLUXDB_ORG=-                     # required by the client, ignored by 3
```

InfluxDB 3 Core and Enterprise keep points in databases, and have no
retention policies. What the upstream client writes through the 2.x API lands
in the database named after the bucket, slash included: a bucket named
`telegraf/autogen` is database `telegraf/autogen`, created by the first write.
InfluxQL reads that same database, so a `v3` connection uses the bucket whole
rather than splitting it, and sends no retention policy. Set `database` to
read another database; a `retentionPolicy` throws an
`InvalidArgumentException`, since there is none to name. A measurement
qualified with a database reads the database the way InfluxDB 3 names it:
`from('metrics.weekly.cpu')` reads database `metrics/weekly`, but
`from('metrics.autogen.cpu')` reads `metrics`.

InfluxDB 3 runs the builder's statements as 1.x does, `tz()`, `fill()` and
regular expressions included, but has no `SELECT ... INTO`, `DELETE`, `SLIMIT`
or `SOFFSET`: a `v3` connection refuses `into()`, `delete()`, and `slimit()`
or `soffset()` with any value but 0, before anything is sent. It also answers
SQL: see [Querying InfluxDB 3 with SQL](#querying-influxdb-3-with-sql).

### Hypervel's query builder over InfluxQL

`InfluxDB::table()` is the package's own builder, shaped like Hypervel's. The
package also registers an `influxql` database driver, which runs Hypervel's
query builder itself on InfluxQL, on any InfluxDB version. Its connections are
Hypervel's database connections, with their pool, events, query log and
`pretend()`. Add one to your application's `config/database.php`, naming the
InfluxDB connection it runs on:

```php
'connections' => [
    // mysql, pgsql, ...

    'metrics' => [
        'driver' => 'influxql',
        'connection' => 'main',   // an InfluxDB connection of any version; the default one when left out
    ],
],
```

Its statements are compiled for that InfluxDB connection's `version`, and sent
to the database and retention policy the connection reads, as
[configured](#configuring-influxql) for `InfluxDB::table()`. Then query it as
any other database connection:

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
[Models of measurements](#models-of-measurements).

It has the clauses [InfluxQL adds](#what-influxql-adds), with the same names:
`groupByTime()`, `fill()`, `slimit()`, `soffset()`, `tz()`, `into()` and
regular expressions. It refuses what the connection's version cannot run, as
`InfluxDB::table()` does. What to expect beyond that:

- **What InfluxQL cannot express is refused before anything is sent.** Each
  of these throws, either an `InvalidArgumentException` as the method is
  called, or a `RuntimeException` as the statement compiles:
  - joins, havings, unions and sub-selects;
  - null comparisons, such as `whereNull()` or `where()` with a null value;
  - `whereNot()`, `whereLike()` and `whereExists()`;
  - the JSON and full-text clauses;
  - the date-part clauses, such as `whereDate()`, `whereYear()` and
    `whereToday()`;
  - an SQL operator such as `like`;
  - an order by anything but `time`.

  `wherePast()` and `whereFuture()` compare `time` with now, and work. Writes,
  `truncate()` and transactions throw a `LogicException`; write points through
  `InfluxDB::writeApi()`. `lockForUpdate()`, `sharedLock()` and `timeout()`
  compile to nothing.
- **A column qualified by the measurement is taken unqualified.** `cpu.time`
  is read as `time`, since InfluxQL names a field or tag on its own; Eloquent
  qualifies columns this way. A column of another measurement is left as it
  is.
- **Rows are read as InfluxQL returns them.** Each is an object keyed by
  column, `time` first, with the `GROUP BY` tags of its series appended.
  `value()`, `pluck()` and the aggregates read the field they asked for. For a
  column the server names itself, such as the `mean` of `mean("usage_user")`,
  they read the first field after `time`. `exists()` asks for one point.
  `series()` returns the series themselves, each with its `name`, `tags`,
  `columns` and `values`.
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
- **`delete()`** deletes as [`InfluxDB::table()`'s does](#what-influxql-adds)
  on each version. It takes no id, and returns 0, since InfluxQL reports no
  count.
- **The values are embedded in the statement**, as `InfluxDB::table()` embeds
  them, so what is sent is what `toRawSql()` shows. A date is sent in UTC,
  with microseconds: `'2024-01-01T00:00:00.000000Z'`.
- **The database is the InfluxDB connection's.** A `database` set in
  `config/database.php` is replaced by the database the statements go to, and
  `setDatabaseName()` does not redirect them. To read another database, use
  another InfluxDB connection or a qualified measurement, such as
  `from('telegraf.weekly.cpu')`.
- **Errors are Hypervel's.** A statement the server refuses, or one that never
  reaches it, throws a `Hypervel\Database\QueryException`. It names the
  database connection, the server's host and port, the database and the
  statement. So does a server that runs another major version than the
  InfluxDB connection names. Each pooled connection asks for the version once,
  before its first statement, unless it was
  [read when the server started](#the-builder-checks-the-servers-version).
  A statement that fails because the connection was lost is sent once more,
  on a new connection.

## Querying InfluxDB 3 with SQL

InfluxDB 3 also answers SQL, which it plans with Apache DataFusion, a dialect
that follows PostgreSQL's. The package registers it as an `influxdb` database
driver, so Hypervel's own query builder, raw queries and read-only Eloquent
models run on an InfluxDB 3 database: joins, unions and window functions
included.

Add a connection to your application's `config/database.php`. It names the
InfluxDB connection it runs on, whose `version` must be `v3`, and the
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

Then query it as any other database connection:

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

An Eloquent model reads through the connection too. Extend
[`Measurement`](#models-of-measurements), which makes the settings a time
series needs and refuses to write:

```php
use Ipsocode\InfluxDB\Eloquent\Measurement;
use UnitEnum;

class Cpu extends Measurement
{
    protected UnitEnum|string|null $connection = 'influxdb';

    protected ?string $table = 'cpu';

    protected array $casts = ['time' => 'datetime'];
}
```

What to expect from it:

- **It is read-only.** InfluxDB 3 answers SQL reads only, so inserts,
  updates, upserts, deletes and truncates, an Eloquent `save()` included,
  throw a `LogicException` before anything is sent. Write points through
  `InfluxDB::writeApi()`. There are no transactions either: `transaction()`
  and `beginTransaction()` throw a `LogicException`, and `lockForUpdate()`
  and `sharedLock()` compile to nothing, as they do on SQLite.
- **The values are embedded in the statement.** Each statement is one POST to
  `/api/v3/query_sql`, with its values escaped into it, so what is sent is
  what `toRawSql()` shows. A string is quoted with its `'` doubled, the only
  escape DataFusion reads, and a backslash is itself. A boolean is `TRUE` or
  `FALSE`, since DataFusion does not compare a boolean with 0 or 1. A date is
  RFC3339 with microseconds and its own offset, such as
  `2024-01-01T02:00:00.000000+02:00`, so it compares on the instant it names,
  whatever its time zone.
- **Rows are objects keyed by column.** InfluxDB 3 leaves a column out of a
  row whose value is null, so each row is given every column the other rows
  of the result have, null where it was left out. A column that is null in
  every row cannot be told from one that was never selected, and is not given.
  Timestamps come back in UTC without an offset, such as
  `2024-01-01T00:00:00`.
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
InfluxDB server's host and port, the database, and the statement. The
connections are Hypervel's: its database manager makes them through the
driver, and its connection pool keeps them, one per coroutine at a time, each
with its own HTTP transport. The InfluxDB connection's options, such as
`timeout`, `proxy`, `verifySSL` and `httpClient`, apply to that transport as
they do to writes.

## Models of measurements

`Ipsocode\InfluxDB\Eloquent\Measurement` is a read-only Eloquent model of a
measurement. It runs on either database driver: `influxql`, on any InfluxDB
version, or `influxdb`, on InfluxDB 3. Extend it with the connection and the
measurement it reads:

```php
use Hypervel\Database\Eloquent\Relations\HasMany;
use Ipsocode\InfluxDB\Eloquent\Measurement;
use UnitEnum;

class Cpu extends Measurement
{
    protected UnitEnum|string|null $connection = 'metrics';   // an influxql connection

    protected ?string $table = 'cpu';

    public function mem(): HasMany
    {
        return $this->hasMany(Mem::class, 'host', 'host');
    }
}

$latest = Cpu::query()->where('host', 'web1')->latest()->first();

$recent = Cpu::query()->with('mem')->where('time', '>=', now()->subHour())->get();

$hourly = Cpu::query()
    ->selectRaw('mean("usage_user") as "mean"')
    ->where('time', '>=', now()->subDay())
    ->groupByTime('1h')
    ->pluck('mean', 'time');

$perHost = Cpu::query()->selectRaw('max("usage_user")')->groupBy('host')->series();
```

It makes the settings a time series needs:

- **A point is keyed by its `time`.** `find()` and `whereKey()` compare
  `time`, and `latest()` and `oldest()` order by it. `time` is not unique,
  though: several series can each hold a point at the same time, and `find()`
  returns one of them. InfluxQL answers an `OR` between times with no points,
  so on the `influxql` driver `findMany()`, and `whereKey()` given several
  keys, find none.
- **It writes nothing.** `save()` and `delete()` throw a `LogicException`,
  and with them `create()`, `push()`, `destroy()`, `forceDelete()`, their
  quiet variants and `update()` on a model read from the server. Write points
  through `InfluxDB::writeApi()`. A statement the builder sends itself is the
  connection's to refuse: `Cpu::query()->update([...])` throws, but
  `Cpu::query()->where('host', 'web1')->delete()` deletes the points on
  InfluxDB 1.x, as `delete()` does on the
  [connection's builder](#hypervels-query-builder-over-influxql).
- **It keeps no timestamps, and guards nothing**, since every attribute comes
  from the server.
- **Relations load through queries of their own.** `with()` and lazy access,
  such as `$cpu->mem`, work on either driver. On `influxql`, `whereHas()`,
  `has()` and `withCount()` need a sub-select, and are refused.
- **`series()`** returns the points as the server grouped them, with the
  query's scopes applied. It needs the `influxql` driver, and throws a
  `LogicException` on `influxdb`.
- **`time` is not cast.** A `datetime` cast makes the key an object, which
  `chunkById()` and `lazyById()` cannot page on. It also reads an integer as
  seconds, whatever precision the connection's
  [`epoch`](#configuring-influxql) returns. Cast `time`, as
  `protected array $casts = ['time' => 'datetime'];`, only on a connection
  without `epoch`, and page with `chunk()`, `lazy()` or `cursorPaginate()`.
  On `influxql`, `chunkById()` and `lazyById()` are refused either way.

A measurement can name a builder of its own with `#[UseEloquentBuilder]`, as
any Hypervel model can. That builder has to extend
`Ipsocode\InfluxDB\Eloquent\Builder`, or `newQuery()` throws a
`LogicException`.

## Hypervel notes

A Hypervel worker is long-lived and serves many requests at once as
coroutines, so a few things behave differently from PHP-FPM:

- **Clients live as long as the worker.** The manager is a container
  singleton: a connection is built on first use and reused by every request
  the worker serves. `reconnect()`, `disconnect()` and `setDefaultConnection()`
  change what every coroutine on the worker sees, so call them at boot or in
  tests, not per request. To use another connection for one call, pass its
  name to `connection()`, `writeApi()`, `influxql()`, `query()` or `table()`.
  The `WriteApi` and the InfluxQL connection built on a client are kept the
  same way, one of each per connection, and `disconnect()` drops them with
  the client, once a batching writer has sent what it holds. A builder is cheap to start on every request; make an
  `InfluxQL\Connection` yourself (`Connection::make()`) only at boot. The
  connections of the `influxql` and `influxdb` database drivers are the
  exception: they are Hypervel's database connections, which its pool keeps
  and hands to one coroutine at a time, as it does any other database's. Each
  has its own HTTP transport.
- **HTTP calls yield instead of blocking.** Hypervel turns Swoole's runtime
  hooks on (`SWOOLE_HOOK_ALL`, unless the application defines
  `SWOOLE_HOOK_FLAGS`), and they cover curl when Swoole is built with it
  (`php --ri swoole` lists `curl-native => enabled`). The upstream client's
  default Guzzle client goes through curl, so a write or a query suspends the
  coroutine that made it and lets the worker serve other requests meanwhile.
  Without curl support in Swoole, each call to InfluxDB blocks the whole
  worker until it returns.
- **Batching holds points in the worker.** A connection that
  [batches its writes](#batching-writes) buffers them in each worker, sends
  them from coroutines of its own and flushes them on a timer, which the
  worker's exit wakes early. A crash or a `SIGKILL` loses what is buffered;
  synchronous writes, the default, send each point before `write()` returns.

## Testing an application that uses it

`RefPoint` caches its reflection in a static, and the InfluxQL builder keeps
its macros in one. `Testing\TestState` resets both after every test. It is
registered through `extra.hypervel.test-state` in the
package's `composer.json`, so an application's suite picks it up automatically,
provided the framework's PHPUnit extension is registered in `phpunit.xml`:

```xml
<extensions>
    <bootstrap class="Hypervel\Testing\PHPUnit\AfterEachTestExtension"/>
</extensions>
```

Building a client does not connect to anything — `InfluxDB2\Client` opens a
connection only when it sends a request — so resolving connections in tests
needs no running InfluxDB.

## Differences from `ge-tracker/influxdb-laravel`

| | `ge-tracker/influxdb-laravel` | This package |
|---|---|---|
| Framework | Laravel 8 to 12 | Hypervel 0.4 |
| Namespace | `GeTracker\InfluxDBLaravel` | `Ipsocode\InfluxDB` |
| Service provider | `InfluxDBLaravelServiceProvider` | `InfluxDBServiceProvider` |
| Connection manager | `graham-campbell/manager`'s `AbstractManager` | Native; `extend()` and `getConfig()` are not ported, and `getConnectionConfig()` needs a connection name |
| Options passed to the client | A fixed list of seven keys | The connection's whole config |
| `verifySSL` default | `false` | `true` |
| A connection missing `url`, `token`, `bucket` or `org` | Not checked | `InvalidArgumentException` naming the connection and the key |
| A published config's `connections` | Replace the package's | Merged with the package's by name |
| Publishing the config | `vendor:publish`, untagged | `vendor:publish --tag=influxdb-config` |
| `InfluxDB` facade alias | Not registered | Discovered with the provider |
| Writing | `createWriteApi()` per use | `writeApi()`, one `WriteApi` per connection per worker |
| Batching writes | Upstream's: no flush interval, lost when the worker stops, failures thrown at a write | A package writer with size, megabyte and time limits per server version, a drain when the worker exits, and failures reported rather than thrown |
| InfluxQL | Not provided | A query builder shaped like Hypervel's `Query\Builder`, and an `influxql` database driver that runs Hypervel's builder itself, for InfluxDB 1.x, 2.x and 3 |
| SQL | Not provided | A read-only `influxdb` database driver for InfluxDB 3, which gives it Hypervel's query builder and Eloquent |
| Eloquent models | Not provided | `Measurement`, a read-only model of a measurement, on either database driver |
| `RefPoint` | Builds a `ReflectionClass` per point | Caches it, reset by `Testing\TestState` |
| A `RefPoint` made with `new` | Its getters throw an `Error`, since only `from()` sets the point they read | Its getters read its own measurement, tags and fields |
| `RefPoint::setReflection()` | Public | Not ported |

## Contributing

The development setup, the checks CI runs, the coroutine-safety rules every
change is held to, and how releases are cut are in
[CONTRIBUTING.md](CONTRIBUTING.md). Report security issues privately, as
described in [SECURITY.md](.github/SECURITY.md), rather than in a public issue.

## Credits

Originally a port of [`ge-tracker/influxdb-laravel`](https://github.com/ge-tracker/influxdb-laravel)
by James Austen ([GE Tracker](https://www.ge-tracker.com)) to
[Hypervel](https://github.com/hypervel/components). The connection manager
re-implements the parts of
[`graham-campbell/manager`](https://github.com/GrahamCampbell/Laravel-Manager)
by Graham Campbell that upstream relied on. Parts of the InfluxQL query
builder, its grammars and both database drivers are copied from Hypervel's
database component,
[`hypervel/database`](https://github.com/hypervel/components/tree/0.4/src/database),
which Hypervel ported from Taylor Otwell's Laravel. Talking to InfluxDB is
the job of [InfluxData](https://www.influxdata.com)'s
[`influxdata/influxdb-client-php`](https://github.com/influxdata/influxdb-client-php).

## License

MIT. See [LICENSE](LICENSE), which carries the copyright notices of this
package, of `ge-tracker/influxdb-laravel`, of `graham-campbell/manager` and of
`hypervel/database`.
