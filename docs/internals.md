# Internals

This page is for contributors. It maps `src/`, lists where the package keeps
state in a long-lived worker, and explains how server version detection, the
batching writer, the InfluxQL dialect and the two database drivers work. How
to use the package is under [Usage](../README.md#usage) and on the other pages
here.

## Layout of `src/`

The namespace `Ipsocode\InfluxDB` is rooted at `src/`.

| Path | What it holds | Described in |
|---|---|---|
| `InfluxDBServiceProvider.php` | Registers the factory, the manager, the `InfluxDB2\Client` binding and both database drivers; merges a published config's `connections` with the package's by name; listens for `BeforeServerFork`, `AfterExecute` and `Terminating` | [The connection manager](hypervel.md#the-connection-manager) |
| `InfluxDBManager.php`, `Facades/InfluxDB.php` | The container singleton behind the `InfluxDB` facade: resolves named connections, memoises a client, a `WriteApi` and an InfluxQL connection for each, and detects the servers' versions | [The connection manager](hypervel.md#the-connection-manager) |
| `InfluxDBFactory.php` | Builds an `InfluxDB2\Client` from a connection's config | [Passing options to the client](configuration.md#passing-options-to-the-client) |
| `Write/BatchingWriter.php` | The `WriteApi` of a connection whose `writeType` is `WriteType::BATCHING` | [Batching writes](writing.md#batching-writes), [below](#the-batching-writer) |
| `Write/BatchOptions.php` | The batching options, checked, with the size defaults of each version | [Batching writes](writing.md#batching-writes), [When a batch is sent](writing.md#when-a-batch-is-sent) |
| `Write/Batch.php`, `Write/BatchWriteException.php`, `Write/BufferFullException.php` | One request's line protocol; a batch dropped after its retries; a write refused past `maxBuffered` | [When a batch cannot be written](writing.md#when-a-batch-cannot-be-written), [How much a worker holds](writing.md#how-much-a-worker-holds) |
| `InfluxQL/Connection.php`, `V1Connection.php`, `V2Connection.php`, `V3Connection.php` | Run InfluxQL on `/query`, addressed to a database and retention policy; one subclass per version, picked by `Connection::make()` | [Running statements](influxql.md#running-statements); connecting to [1.x](configuration.md#connecting-to-influxdb-1x), [2.x](configuration.md#connecting-to-influxdb-2x) and [3](configuration.md#connecting-to-influxdb-3) |
| `InfluxQL/Version.php` | The InfluxDB major versions, and the statements each runs | [Choosing the server version](configuration.md#choosing-the-server-version) |
| `InfluxQL/QueryApi.php` | The transport: `/query` and `/ping`, on top of the client's `DefaultApi` | [Running statements](influxql.md#running-statements) |
| `InfluxQL/Result.php`, `Series.php`, `QueryException.php` | One statement's result, one series of it, and a statement that failed | [Running statements](influxql.md#running-statements) |
| `InfluxQL/Builder.php` | The query builder behind `InfluxDB::table()`, shaped like Hypervel's `Query\Builder` | [The query builder](influxql.md#the-query-builder) |
| `InfluxQL/Grammars/Grammar.php`, `V1Grammar.php`, `V2Grammar.php`, `V3Grammar.php` | Compile that builder into InfluxQL; one subclass per version | [Below](#the-influxql-dialect) |
| `InfluxQL/Grammars/Concerns/RefusesWhatTheVersionLacks.php` | The refusals both InfluxQL grammars share | [Choosing the server version](configuration.md#choosing-the-server-version), [below](#the-influxql-dialect) |
| `InfluxQL/Dialect.php` | Quoting, literals and placeholder substitution, shared by both InfluxQL grammars | [Below](#the-influxql-dialect) |
| `InfluxQL/Expression.php`, `Regex.php` | A raw fragment embedded as written, and a `/pattern/` literal | [What carries over from Hypervel's builder](influxql.md#what-carries-over-from-hypervels-builder), [What InfluxQL adds](influxql.md#what-influxql-adds) |
| `InfluxQL/Driver/Connection.php`, `Builder.php`, `Grammar.php` | The `influxql` database driver: Hypervel's query builder over InfluxQL | [What to expect](influxql-driver.md#what-to-expect) |
| `Sql/SqlConnection.php`, `SqlGrammar.php`, `SqlApi.php` | The `influxdb` database driver: SQL on InfluxDB 3 | [What to expect](sql.md#what-to-expect) |
| `Eloquent/Measurement.php`, `Builder.php` | The read-only model of a measurement, and the Eloquent builder it queries through | [The settings a time series needs](eloquent.md#the-settings-a-time-series-needs) |
| `Support/RefPoint.php` | Reads a built `InfluxDB2\Point` back | [Reading points back with `RefPoint`](writing.md#reading-points-back-with-refpoint) |
| `Testing/TestState.php` | Resets the package's static state after each test | [Testing an application that uses it](hypervel.md#testing-an-application-that-uses-it), [below](#where-the-package-keeps-state) |

Outside `src/`, `config/influxdb.php` is the config the provider merges and
publishes, and `workbench/` is the host application the suite runs against,
described in [CONTRIBUTING.md](../CONTRIBUTING.md).

Two builders and two grammars compile InfluxQL: `InfluxQL\Builder` and
`InfluxQL\Grammars\Grammar` for `InfluxDB::table()`, and
`InfluxQL\Driver\Builder` and `InfluxQL\Driver\Grammar` for the `influxql`
driver. The grammars share `Dialect` and `RefusesWhatTheVersionLacks`, so a
statement is written, and refused, the same way whichever compiles it.
`InfluxQL\Driver\Connection` and `Sql\SqlConnection` are built alike too. Keep
each pair in step: a change to one usually needs the same change to its twin.

## Where the package keeps state

The coroutine-safety rules every change is held to are in
[CONTRIBUTING.md](../CONTRIBUTING.md), and what a long-lived worker means for
an application is in [Long-lived workers](hypervel.md#long-lived-workers). The
package keeps state, shared by every coroutine of a worker, in these places:

- **The manager's caches.** `InfluxDBManager` is a container singleton, so its
  resolved clients, memoised `WriteApi`s and InfluxQL connections live as long
  as the worker. They are instance properties, so they go when a test rebuilds
  the container. `reconnect()`, `disconnect()` and `setDefaultConnection()`
  change what every coroutine on the worker sees, and are for boot or tests
  only ([The connection manager](hypervel.md#the-connection-manager)).
- **Batching writers' buffers.** A `Write\BatchingWriter`, memoised by the
  manager like any `WriteApi`, holds the points it has not sent in the worker,
  with its flush timer and the coroutines sending its full batches. Every
  change to the buffer is made without yielding, and a batch is taken out of
  it before its send yields, so coroutines that write meanwhile start new
  batches ([The batching writer](#the-batching-writer)). The flush timer waits
  on the `WORKER_EXIT` coordinator, which Hypervel's test harness resumes after
  each test, so a test that leaves points buffered sends them as the test
  ends: give its transport a response for them. The provider flushes every
  writer when a console command finishes (`AfterExecute`) and when the console
  application terminates outside a coroutine (`Terminating`).
- **SQL connections.** Not the package's: the provider registers the
  `influxdb` database driver on Hypervel's `DatabaseManager`, which makes a
  `Sql\SqlConnection` for each slot of the connection's pool, and the pool
  hands each one to a single coroutine at a time. Every connection builds its
  own `Sql\SqlApi` from its InfluxDB connection's options, so no HTTP client
  is shared between them unless the options inject one.
- **InfluxQL database connections.** Not the package's either: the `influxql`
  driver works the same way, making an `InfluxQL\Driver\Connection` for each
  slot of the pool. Each wraps an `InfluxQL\Connection` of its own, rather
  than the manager's memoised one. That connection builds its own `QueryApi`
  from the options of the manager's client, so no HTTP transport is shared,
  with the manager or between these connections, unless the options inject an
  `httpClient`. Each keeps the server version the master process read, or asks
  the server once, before its first statement. Each also keeps its InfluxDB
  version and retention policy, so a grammar rebuilt while it is disconnected
  still refuses what that version cannot run. The driver's builder adds no
  static state; its macros are Hypervel's `Query\Builder`'s, which Hypervel
  flushes.
- **The servers' versions.** Read once in the master process when the server
  starts, before it forks its workers
  (`InfluxDBManager::detectServerVersions()`, on Hypervel's
  `BeforeServerFork`), and kept in the non-coroutine context under
  `__influxdb.server_versions`, which every worker inherits. Each ping closes
  its connection, so no socket crosses the fork. Hypervel's test harness
  flushes the non-coroutine context after each test. See
  [Server version detection](#server-version-detection).
- **`RefPoint`'s reflection cache.** A static, reset by
  `RefPoint::flushState()`.
- **The InfluxQL builder's macros.** A static, reset by
  `InfluxQL\Builder::flushState()`.

`Testing\TestState` flushes the static state: its `register()` hands
`flushState()` to Hypervel's `AfterEachTestCleanup`. It is declared in
`composer.json`'s `extra.hypervel.test-state`, so a consuming application's
suite resets this package's state too
([Testing an application that uses it](hypervel.md#testing-an-application-that-uses-it)).
For the package's own suite that registrar is discovered by the
`AfterEachTestExtension` bootstrap in `phpunit.xml`; without that entry the
callbacks are declared and never run.

## Server version detection

The InfluxQL builder and the `influxql` driver compile for the version a
connection names, and refuse to send a statement to a server that runs another
major version, or names none
([The builder checks the server's version](influxql.md#the-builder-checks-the-servers-version)).
Each server is asked once, when the Hypervel server starts, and every worker
inherits the answer. A connection whose answer is missing asks its server
before its first statement instead.

### At server start

`InfluxDBServiceProvider::boot()` listens for Hypervel's `BeforeServerFork`,
which fires in the master process, outside any coroutine, just before the
server forks its workers. The listener calls
`InfluxDBManager::detectServerVersions()`, which, for every connection under
`influxdb.connections`:

1. builds a client through `InfluxDBFactory::make()`, for its options, so a
   connection missing `url`, `token`, `bucket` or `org` throws and is left
   out;
2. sends `GET /ping` through a new `InfluxQL\QueryApi`, with a
   `Connection: close` header, and reads the `X-Influxdb-Version` header of the
   answer: a `204` from InfluxDB 1.x and 2.x, a `200` with a JSON body from
   InfluxDB 3.

A transport of its own for each ping, and the header, leave no socket open for
the workers to inherit across the fork, even when the options inject an
`httpClient` the workers share. A connection whose ping throws, or whose
server names no version, is left out. A server that does not answer holds the
start for up to the connection's `timeout`.

The answers are kept as one array, connection name to version, in the
non-coroutine context under `__influxdb.server_versions`.
`CoroutineContext::set()` writes there only outside a coroutine; inside one it
writes to that coroutine's context, which `getDetectedServerVersion()` does
not read. That is why `detectServerVersions()` is for boot only. Hypervel's
test harness flushes the non-coroutine context after each test, so a version
detected in a test does not outlive it.

### In a worker

A forked worker inherits the master's memory, the non-coroutine context
included. `InfluxDBManager::getDetectedServerVersion()` reads it with
`CoroutineContext::getFromNonCoroutine()`, so code inside a coroutine reads it
too. It returns null for a connection that was left out, and for a name no
connection has.

`InfluxDBManager::influxql()` hands that version to the `InfluxQL\Connection`
it memoises, through `setServerVersion()`. `Connection::getServerVersion()`
returns it, or else asks `/ping` on the connection's own `QueryApi` the first
time it is called, and keeps the answer once the server names one; a server
that names none is asked again next time. `knowsServerVersion()` says whether
a ping is still to come.

The builder sends every statement through `send()`, which calls
`ensureServerRunsTheConnectionsVersion()` first. When a ping is still to come,
it embeds the bindings before the ping, as the connection would before sending,
so a value InfluxQL has no literal for fails before anything is sent, the ping
included. `Version::matches()` compares the major version: InfluxDB 1.x
reports a bare version, such as `1.8.10`, 2.x one with a `v` in front, such as
`v2.7.12`, and 3 a bare one again, such as `3.11.5`, and either spelling is
read for every version. A ping that fails, and a server that runs another
major version or names none, are each refused with an `InfluxQL\QueryException`
carrying the statement that was not sent, its bindings embedded. The check
lives in the builder, so a statement run on the connection itself, such as
`InfluxDB::influxql()->select()`, is not checked.

### In the database drivers

`InfluxQL\Driver\Connection::fromConfig()` makes its `InfluxQL\Connection` the
way the manager does, and hands it the detected version too. Before each
statement its `ensureServerRunsTheVersion()` makes the same comparison. A
pooled connection whose version was not read at start asks the server before
its first statement, and again after the pool reconnects it, since a
reconnected connection holds a new `InfluxQL\Connection`. Its refusal is a
`RuntimeException`, which Hypervel's `run()` wraps in its `QueryException`.

`Sql\SqlConnection` checks no version before a statement: its `fromConfig()`
refuses an InfluxDB connection whose `version` is not `v3`, and its
`getServerVersion()` reads the `version` of the JSON object InfluxDB 3 answers
`/ping` with.

## The batching writer

`Write\BatchingWriter` is what `writeApi()` returns for a connection whose
`writeType` is `WriteType::BATCHING`. What it does for an application is in
[Batching writes](writing.md#batching-writes). This section is how it stays
correct while many coroutines write to the one instance a worker holds.

### Built beside the client

`InfluxDBManager::makeWriteApi()` builds the writer on the client's options
rather than through `Client::createWriteApi()`, which keeps a reference to
every `WriteApi` it creates, for its own `close()`. The manager closes the
writer instead, on `disconnect()`. `BatchOptions::batching()` decides whether
the write options ask for batching, and `BatchOptions::fromConfig()` checks
them and fills in the size defaults of the connection's `Version`.

### The synchronous parent

The writer extends `InfluxDB2\WriteApi`. Its constructor sets `writeType` to
`WriteType::SYNCHRONOUS` in the write options it hands to the parent, and
`maxRetries` to `BatchOptions::DEFAULT_MAX_RETRIES`, 3, unless one is set.
With `BATCHING` left in, the parent's `write()` would push into the client's
own batching worker. As `SYNCHRONOUS`, it serialises a point as a synchronous
`WriteApi` does, default tags, precision, bucket and org included, and hands
the line protocol to `writeRaw()`, which the writer overrides to buffer.
`send()` posts a batch through `parent::writeRaw()`, the client's synchronous
write with its retries.

The writer's own `write()` takes a list of points (an array without a `name`
key) apart and writes each one, so a batch can end between any two of them; a
string of line protocol is buffered whole. `close()` marks the writer closed
and flushes it.

### Take before yield

Every change to the buffer (`$batches`, `$buffered`, `$sending` and
`$bufferedSince`) is made without yielding. `take()` removes a batch from
`$batches` and moves its points from `$buffered` to `$sending` before the send
that follows can yield, so a write that lands during the send starts a new
batch, and no two sends carry the same point. `send()` takes the points off
`$sending` in a `finally`, after `onFailure` has run, so `getPendingPoints()`
and the `maxBuffered` check count a batch until its send is over.

`buffer()` first dispatches the batch the new lines would take past
`batchSize` or `batchBytes`, so no request outgrows them, then adds the lines,
and dispatches their batch once it is full. `dispatch()` sends from a
coroutine of its own, registered in `$sends` with a `Coordinator` its
`finally` resumes. `flush()` walks the batch keys it found when it started and
takes each batch only when its turn comes, so a batch another coroutine takes
meanwhile is not sent twice, and one this coroutine never reaches, if it is
cancelled, is still buffered. Then `awaitSends()` waits on every coordinator
in `$sends` but the calling coroutine's own, which would wait for itself.

A write that would take the points waiting, buffered or being sent, past
`maxBuffered` is refused before anything changes when `overflow` is `refuse`;
otherwise it is buffered, and the writing coroutine then flushes.

### The flush timer

`scheduleFlush()` arms a `Hypervel\Coordinator\Timer::after()` for
`flushInterval` when the buffer holds points and no timer is armed.
`Timer::after()` waits on the `WORKER_EXIT` coordinator, which the worker's
exit resumes, so the timer fires early and the buffer is sent before the
worker stops. `flush()` clears the timer first (`cancelFlushTimer()`).

`$flushTimerGeneration` counts every time the timer is armed, fires or is
cleared. `Timer::after()` starts its callback in a new coroutine before it
returns the timer's ID, and once the worker is exiting that callback does not
wait: it fires, clearing `$flushTimer` and counting, before `after()` returns.
The writer keeps the returned ID only while the count is still the one it took
when it armed the timer. Otherwise `$flushTimer` would hold the ID of a timer
that has already fired, and no later write would arm another: while the worker
exits, only the first write would be sent at once.

### Outside a coroutine

With no coroutine to run in (`Coroutine::inCoroutine()` is false), as in a
task worker without coroutines, there is no timer to arm and no coroutine to
send from. `dispatch()` sends the batch in the write that filled it,
`scheduleFlush()` flushes once the buffer is older than `flushInterval` by
`hrtime()`, and `awaitSends()` has nothing to wait for. A console command's
batches are sent by the provider when it finishes
([The connection manager](hypervel.md#the-connection-manager)).

### Failures

`attempt()` runs a callback and returns what it throws rather than throwing
it, except Swoole's `CanceledException`, which it throws on: a cancelled
coroutine has to unwind, as Hypervel expects, not be taken for a failed batch.
`send()` runs the post through it, and hands a failure to `fail()`, which wraps
it in a `BatchWriteException`, reports that, and calls `onFailure`, a callable
or a class name resolved from the container, through `attempt()` again,
reporting what that throws. `report()` goes through the container's
`ExceptionHandler`, and falls back to `error_log()` when that throws, as it
does when no handler is bound. No failed batch is thrown at a `write()`.

## The InfluxQL dialect

How InfluxQL writes identifiers and literals lives in `InfluxQL\Dialect`, a
class of static methods with no state. Both grammars delegate to it,
`Grammars\Grammar` for `InfluxDB::table()`'s builder and `Driver\Grammar` for
the `influxql` driver, so a statement reads the same whichever compiled it. It
knows neither grammar's raw expression type: each grammar embeds its own
expressions as written, and hands every other value to `Dialect::escape()`.

`Driver\Grammar` extends Hypervel's query grammar, and `Grammars\Grammar` is
shaped like it: a select is assembled component by component, each `where`
type has a small compiler of its own, and there is one subclass per InfluxDB
version, as Hypervel has one per database. Where InfluxQL differs from SQL:

### Quoting

- An identifier is double-quoted and a string single-quoted. Both escape their
  quote and a backslash with a backslash, not by doubling the quote as SQL
  does, and write a newline as `\n`, since InfluxQL refuses a literal one:
  `quoteString("it's")` is `'it\'s'`.
- A trailing type hint, `::tag`, `::field` or a cast (`::float`, `::integer`,
  `::unsigned`, `::string`, `::boolean`, in any case), is syntax, so it stays
  outside the quotes, in lower case: `host::TAG` is `"host"::tag`.
  `stripTypeHint()` gives the bare name back, since InfluxDB returns a column
  selected with a hint under its name alone. The `*` wildcard is not quoted.
- A regular expression is its own literal, `/pattern/`. A `Regex` carries the
  pattern without delimiters, and `quoteRegex()` adds them and escapes every
  slash not already escaped: `^/var/log` is `/^\/var\/log/`. A plain string
  could not say which quoting it wants, so a pattern travels as a `Regex`
  binding, and a measurement given as one compiles to a `?`.

### Literals

- A date is written by `formatDateTime()` as RFC3339 in UTC, to the
  microsecond (`Dialect::DATE_FORMAT`, `Y-m-d\TH:i:s.u\Z`), and quoted as a
  string: `2024-01-01 02:00:00+02:00` is `'2024-01-01T00:00:00.000000Z'`.
  InfluxQL parses fractional seconds to the nanosecond and reads the `Z` as
  UTC, so no offset is left to the server.
- A float is never written with an exponent, which InfluxQL's scanner does not
  read, and always keeps its decimal point, since a literal without one is an
  integer, which overflows above 2^63. `formatFloat()` takes the digits of
  `var_export()`'s shortest round-trip form and moves the point, never
  rounding: `1.0E+20` is `100000000000000000000.0`, and `1.0E-9` is
  `0.000000001`. NAN and INF have no literal, and throw an
  `InvalidArgumentException`.
- `escape()` writes a `Regex`, a date, a `Stringable` (as a string), a boolean
  (`true` or `false`), an integer, a float or a string. Anything else throws an
  `InvalidArgumentException`: null, since InfluxQL has no null literal; an
  array, with a pointer to `whereIn()` and `whereBetween()`; and any other
  object.

### Placeholders

A grammar compiles every value to a `?`, and the values are embedded only as
the statement is sent, rather than passed as the `/query` endpoint's `$name`
parameters, so the statement that runs is exactly the one `toRawSql()` shows.
`substituteBindings()` walks the statement a character at a time and fills
each `?` outside a string literal or a quoted identifier, honouring the
backslash escapes both use. The values are embedded in order, whatever their
keys, and not scanned again; a `?` with no value left stays as it is. The walk
does not know a regular-expression literal, so a `?` written inside a raw
`/…/` is taken for a placeholder: pass the pattern as a `Regex` binding.

### What the grammars rewrite

InfluxQL has no `IN`, `BETWEEN`, `NOT`, `NULL` or `LIKE`. Both grammars
expand:

- `whereIn()` into the `OR` of one `=` per value, and `whereNotIn()` into the
  `AND` of one `!=` per value; an empty list compiles to `0 = 1` and `1 = 1`,
  as in Hypervel;
- `whereBetween()` into `(col >= min AND col <= max)`, inclusive like
  `BETWEEN`, and its negation into `(col < min OR col > max)`.

A null value is refused rather than sent. A column name is quoted whole and
never split on dots: InfluxQL has no `measurement.column` form, and a dot is an
ordinary character in a field or tag key. An alias (`value as v`) and a type
hint are the two shapes taken apart. The `influxql` driver's builder drops a
qualifier naming the measurement the query reads, as Eloquent writes
`cpu.time`, before the grammar sees the column. Only a measurement is split on
dots, into the `"db"."rp"."cpu"` form InfluxQL gives `FROM`, segment by
segment; `db..cpu` keeps its empty middle segment, which stands for the
default retention policy. A measurement whose own name holds a dot is passed
as an expression: an `InfluxQL\Expression`, or `DB::raw()` on the driver.

An aggregate is aliased `aggregate`, as Hypervel reads it back, except over
`*`: InfluxQL expands `COUNT(*)` into one column per field, and an alias on
that is an error. `DISTINCT` is a function around each column, not a keyword
on the clause, and `Driver\Grammar` writes Hypervel's `avg` as InfluxQL's
`mean`, which `InfluxDB::table()`'s builder asks for itself.
`Grammars\Grammar` writes keywords in upper case and `Driver\Grammar` in lower
case, as Hypervel does; InfluxQL reads either.

### Refusing what the version lacks

Which statements each InfluxDB version runs is on `InfluxQL\Version`, and
written out in [Choosing the server version](configuration.md#choosing-the-server-version).
`Grammars\Concerns\RefusesWhatTheVersionLacks`, used by both grammars, turns
it into refusals as a statement compiles, before anything is sent, with the
same message whichever grammar compiles it: `ensureVersionCompilesInto()`;
`compileSeriesLimit()` for `SLIMIT` and `SOFFSET`, which compiles 0, their
no-op, to nothing on a version without them; `ensureVersionCompilesDelete()`,
which also takes the retention policy the connection addresses; and
`ensureDeleteTakesABareMeasurement()`. `V1Grammar`, `V2Grammar` and
`V3Grammar` only name their `Version`; `Driver\Grammar` is handed the version
and the retention policy by its connection.

## The database drivers

The provider registers both drivers on Hypervel's `DatabaseManager` once it is
resolved (`callAfterResolving('db', …)`, then `extend()`): `influxdb` makes a
`Sql\SqlConnection`, and `influxql` an `InfluxQL\Driver\Connection`, each
through its `fromConfig()` with the manager. Hypervel's database manager and
pool keep the connections. What an application sees is in
[Hypervel's query builder over InfluxQL](influxql-driver.md#what-to-expect)
and [Querying InfluxDB 3 with SQL](sql.md#what-to-expect).

Both extend `Hypervel\Database\Connection` without a PDO. `fromConfig()`
resolves the InfluxDB connection the config names, the default one when it
names none, and fills in the `host` and `port` the connection reports in its
exceptions from the client's URL.

### The `influxql` driver

`Driver\Connection` wraps an `InfluxQL\Connection` of its own, made by
`InfluxQL\Connection::make()` from the manager's client and the InfluxDB
connection's config, rather than the manager's memoised one, so its
`QueryApi` is its own, and so is its HTTP client unless the options inject an
`httpClient`. A `database` in its config is replaced by the database that
connection addresses. It also keeps the `Version` and the retention policy, so
`getDefaultQueryGrammar()` can build
`new Grammar($this, $version, $retentionPolicy)` while the connection has let
go of its InfluxQL connection.

Each statement goes through `runInfluxQL()` inside Hypervel's `run()`, which
logs it and fires the query events. A pretending connection sends nothing.
Otherwise the statement is embedded, then the server's version checked, then
the statement sent:

1. The grammar's `substituteBindingsIntoRawSql()` embeds a Hypervel expression
   as written and every other value with `Dialect::escape()`, since the
   connection's `escape()` is typed for SQL's scalars, with no literal for a
   regular expression or a date. `prepareBindings()` has already written a
   date as RFC3339 in UTC, and kept a boolean rather than casting it to 0 or 1
   as Hypervel does for PDO. A value InfluxQL has no literal for fails here,
   before anything is sent, a `/ping` included.
2. `ensureServerRunsTheVersion()` asks `/ping` if the version is not known yet
   ([Server version detection](#server-version-detection)).
3. The statement goes to the InfluxQL connection's `select()`, `results()` or
   `statement()`.

The InfluxQL connection's failure is an `InfluxQL\QueryException`. The driver
throws its cause bare, or a `RuntimeException` with the server's error when it
has none, since Hypervel's `run()` wraps it in its own `QueryException`, which
adds the connection and the statement.

### The `influxdb` driver

`SqlConnection::fromConfig()` refuses an InfluxDB connection whose `version` is
not `v3`, takes the database from the config or else the connection's bucket,
and builds a `Sql\SqlApi` of its own from the client's options. `SqlApi` posts
each statement as JSON, `{"db": …, "q": …, "format": "json"}`, to
`/api/v3/query_sql`, and decodes an integer too large for PHP as a numeric
string. A statement the server refuses is a `400`, which the client throws as
an `InfluxDB2\ApiException`; one that fails once its rows have started
streaming leaves a body that is not a list of rows, which `SqlApi` throws as
an `ApiException` too. Hypervel's `run()` wraps either in its
`QueryException`.

`select()` runs through Hypervel's `run()`, embedding the values with the
grammar before it sends. `statement()`, `affectingStatement()` and
`unprepared()` throw a `LogicException`, since InfluxDB 3 answers SQL reads
only. `prepareBindings()` writes a date in the grammar's date format and keeps
a boolean rather than casting it to 0 or 1, and `escapeString()` and
`escapeBool()` write a string and a boolean as DataFusion reads them: see
[What to expect](sql.md#what-to-expect).

### What both drivers share

- **A statement lost to a dropped connection is sent once more.** Hypervel's
  `run()` wraps a failure in its `QueryException`, and when its lost-connection
  detector knows the cause's message, such as `reset by peer`, it has the pool
  make a fresh connection through the driver and runs the statement again,
  once. `replaceDriverResources()` takes over the fresh connection's
  `SqlApi`, or, for `influxql`, its InfluxQL connection, version and
  retention policy, rebuilding the grammar, along with its database, table
  prefix and config.
- **No session to end.** `disconnectDriverResources()` lets go of the
  transport, or the InfluxQL connection, and its HTTP client with it;
  `reconnectIfMissingConnection()` makes a new one before the next statement.
- **`ping()` is the pool's health check.** It is `@internal`: a `GET /ping`,
  false when it fails, with a `CanceledException` thrown on rather than taken
  for a failure. A connection that has let go of its transport has nothing
  open to check, and counts as responsive until it next reconnects.
- **No transactions.** `inTransaction()` is always false.
- **Whole results.** InfluxDB answers with the whole result at once, so
  `cursor()` reads it as `select()` does and then yields the rows one at a
  time.

### `SqlGrammar` and Hypervel's PostgreSQL grammar

InfluxDB 3 plans SQL with Apache DataFusion, whose dialect follows
PostgreSQL's: double-quoted identifiers, a doubled `'` as the only escape in a
string, the `~` family of regular-expression operators, `ILIKE`, `::` casts,
`extract()`, `DISTINCT ON`, joins, unions and window functions. So `SqlGrammar`
extends Hypervel's `PostgresGrammar` and changes what DataFusion reads
differently:

- `compileExists()` writes `select true as "exists" where exists(…)`.
  DataFusion plans `EXISTS` in a where clause but not in a select list, so the
  statement returns one row when the query has any and none otherwise, which
  the builder's `exists()` reads as false.
- `compileLock()` compiles `lockForUpdate()` and `sharedLock()` to nothing, as
  for SQLite, since there is no transaction for a lock to last in; a lock given
  as a string is still written as given.
- `compileThreadCount()` returns null: InfluxDB 3 serves SQL over stateless
  HTTP requests, so there is no count of open connections to ask for.
- `getDateFormat()` is `Y-m-d\TH:i:s.uP`, RFC3339 with microseconds and the
  date's own offset, which DataFusion reads as the instant it names, whatever
  the time zone.
- `substituteBindingsIntoRawSql()` finds placeholders as DataFusion reads the
  statement, since the connection sends what it returns. A `?` counts outside
  string literals, quoted identifiers and `--` or `/* */` comments; a doubled
  quote escapes itself in a literal or an identifier; a backslash escapes only
  in an `E'…'` string, opened by a lone `E` or `e` rather than the last letter
  of a longer word; and `??` is a literal `?`, as for PDO. The values are
  embedded as the connection escapes them, and not scanned again. Hypervel's
  own substitution follows single-quoted strings only, and reads `\'` as an
  escape.

What DataFusion lacks, such as JSON operators and full-text search, still
compiles as PostgreSQL writes it, and the server refuses it.

### Why `InfluxQL\Expression` stands alone

`InfluxQL\Expression` is the raw fragment of `InfluxDB::table()`'s builder,
and does not implement Hypervel's
`Hypervel\Contracts\Database\Query\Expression`. That contract types
`getValue()` to `Hypervel\Database\Grammar`, which is built on a Hypervel
database connection. The standalone InfluxQL grammar does not extend it and
has no such connection, so `InfluxQL\Expression::getValue()` takes a
`Grammars\Grammar` instead. The `influxql` driver's grammar does extend
Hypervel's, so it takes Hypervel's own expressions, such as `DB::raw()`, and
`Driver\Connection::escape()` embeds one as that grammar reads it.
