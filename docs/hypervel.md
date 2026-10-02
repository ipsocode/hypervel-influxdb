# Running on Hypervel

This page covers what the package keeps for the life of a Hypervel worker, how
its HTTP calls yield to other coroutines, the connection manager that hands
out its connections, and testing an application that uses it.

## Long-lived workers

A Hypervel worker is long-lived and serves many requests at once as
coroutines, so a few things behave differently from PHP-FPM.

### Clients live as long as the worker

The manager is a container singleton: a connection is built on first use and
reused by every request the worker serves. The `WriteApi` and the InfluxQL
connection built on a client are kept the same way, one of each per
connection. `reconnect()`, `disconnect()` and `setDefaultConnection()` change
what every coroutine on the worker sees, so call them at boot or in tests, not
per request. To use another connection for one call, pass its name to
`connection()`, `writeApi()`, `influxql()`, `query()` or `table()`.
[The connection manager](#the-connection-manager) has the details.

A builder is cheap to start on every request. Make an `InfluxQL\Connection`
yourself (`Connection::make()`) only at boot: each one builds an HTTP transport
of its own, and, unlike the one `influxql()` keeps, is not handed the version
its server named when the Hypervel server started, so it asks the server
before its builders' first statement.

The connections of the `influxql` and `influxdb` database drivers are the
exception: they are Hypervel's database connections, which its pool keeps and
hands to one coroutine at a time, as it does any other database's. Each has
its own HTTP transport, unless the InfluxDB connection's options inject an
`httpClient`, which they then share. See
[Setting up the `influxql` driver](influxql-driver.md#setting-up-the-influxql-driver)
and [Setting up the `influxdb` driver](sql.md#setting-up-the-influxdb-driver).

### Batches are held in the worker

A connection that [batches its writes](writing.md#batching-writes) buffers
them in each worker, sends them from coroutines of its own and flushes them on
a timer, which the worker's exit wakes early
([When a batch is sent](writing.md#when-a-batch-is-sent)). A crash or a
`SIGKILL` loses what is buffered
([What batching does not solve](writing.md#what-batching-does-not-solve));
synchronous writes, the default, send each point before `write()` returns.

### Server versions are read once, at start

Each connection's server is asked for its version once, as the Hypervel server
starts, and every worker inherits the answer: see
[The builder checks the server's version](influxql.md#the-builder-checks-the-servers-version).
A console command or a test starts no server, so there the InfluxQL builder
and the `influxql` driver ask the server with a `/ping` before their first
statement on each connection;
[Testing an application that uses it](#testing-an-application-that-uses-it)
shows how a test answers it.

## Non-blocking HTTP

Hypervel turns Swoole's runtime hooks on (`SWOOLE_HOOK_ALL`, unless the
application defines `SWOOLE_HOOK_FLAGS`), and they cover curl when Swoole is
built with it (`php --ri swoole` lists `curl-native => enabled`). The InfluxDB
client sends through a Guzzle client of its own by default (Guzzle comes with
`hypervel/foundation`), which goes through curl, so a write or a query
suspends the coroutine that made it and lets the worker serve other requests
meanwhile. Without curl support in Swoole, each call to InfluxDB blocks the
whole worker until it returns.

An `httpClient` set on a connection replaces that Guzzle client (see
[Passing options to the client](configuration.md#passing-options-to-the-client)),
and yields only where Swoole's hooks cover what it sends through.

## The connection manager

`Ipsocode\InfluxDB\InfluxDBManager` is what the `InfluxDB` facade proxies to
and what the container's `influxdb` binding holds; the bindings are listed
under [Usage](../README.md#usage). The provider registers it as a singleton,
so one manager serves every coroutine of a worker, and what it builds lasts
the worker's life.

### What it keeps

Each of these is built on a connection's first call in the worker and
memoised by connection name. Called without a name, they use the default
connection, the config's `default` (see [Connections](configuration.md#connections)).

| Method | Returns |
|---|---|
| `connection($name)` | The connection's `InfluxDB2\Client`, built from its config |
| `writeApi($name)` | The connection's `WriteApi`, a `BatchingWriter` when it [batches](writing.md#batching-writes); see [Writing through `writeApi()`](writing.md#writing-through-writeapi) |
| `influxql($name)` | The connection's `InfluxQL\Connection`, compiled for its `version`; see [Running statements](influxql.md#running-statements) |

`query($name)` and `table($measurement, $name)` start a new builder on that
memoised InfluxQL connection on every call. Any other method called on the
manager, or on the facade, goes to the default connection's client:
`InfluxDB::createQueryApi()` is `InfluxDB::connection()->createQueryApi()`.
`getConnections()` returns the clients built so far, keyed by name,
`getFactory()` the `InfluxDBFactory` that builds them, `getDefaultConnection()`
the default connection's name, and `getConfigName()` the config key the
connections are under, `influxdb`.

`availability()` returns the worker's one `Write\Availability`: which
connections failed to take a write and are skipped for their `cooldown`,
shared by every writer that [falls back to other connections](writing.md#falling-back-to-other-connections).
It is not kept by connection name, and is built on its first call.

`getDetectedServerVersion($name)` returns the version the connection's server
named when the Hypervel server started, which `influxql()` hands its InfluxQL
connection, or `null` when there is none. The provider reads the versions with
`detectServerVersions()` as the server starts, before it forks the workers;
that method is for boot only (see
[Server version detection](internals.md#server-version-detection)).

### Boot or tests only

Each of these changes state that every coroutine on the worker reads:

- `disconnect($name)` forgets the connection's client, and with it the
  `WriteApi` and the InfluxQL connection built on it, which would otherwise
  keep writing and querying through a client the manager has dropped. A
  `BatchingWriter` is closed as it goes: what it holds is sent before
  `disconnect()` returns, and a batch that fails is reported, not thrown
  ([When a batch cannot be written](writing.md#when-a-batch-cannot-be-written)).
- `reconnect($name)` disconnects the connection and builds it again. A
  coroutine already holding the old client, `WriteApi` or InfluxQL connection
  keeps using it.
- `setDefaultConnection($name)` writes the config repository, which every
  coroutine on the worker shares, so the new default applies to all of them.
  To use another connection for one call, pass its name instead.
- `availability()->markUnavailable($name, $seconds)` and `markAvailable($name)`
  change the [cooldowns](writing.md#cooldowns) every writer on the worker
  reads before it picks the connection to write through.

The container's `InfluxDB2\Client` binding (`influxdb.connection`) is a
singleton too: once resolved, it keeps the client it got, and follows neither
`reconnect()` nor `setDefaultConnection()`.

### Batches when a console command finishes

A worker's batches are sent by their flush timer, which the worker's exit
wakes early. A console command has no worker to exit, so the provider calls
`flushAll()` when a command finishes (Hypervel's `AfterExecute` event), rather
than leave the command to wait out the timer, and again when the console
application terminates outside a coroutine (`Terminating`). An HTTP request
terminates inside its own coroutine, and its batches are left to the timer.
`flush($name)` and `flushAll()` are there for your own code too: see
[When a batch is sent](writing.md#when-a-batch-is-sent).

### What it does not have

The manager has no `extend()` and no `getConfig()`.
`getConnectionConfig($name)` needs a connection name: it returns that
connection's config with a `name` key holding the name, and throws an
`InvalidArgumentException` when the connection is not configured.

## Testing an application that uses it

`RefPoint` caches its reflection in a static, and the InfluxQL builder keeps
its macros in one. `Testing\TestState` resets both after every test. It is
registered through `extra.hypervel.test-state` in the package's
`composer.json`, so an application's suite picks it up automatically, provided
the framework's PHPUnit extension is registered in `phpunit.xml`:

```xml
<extensions>
    <bootstrap class="Hypervel\Testing\PHPUnit\AfterEachTestExtension"/>
</extensions>
```

The manager's clients, `WriteApi`s and InfluxQL connections are kept on the
manager, which the container holds, so each test, which builds a fresh
application, starts without them. Building a client does not connect to
anything — `InfluxDB2\Client` opens a connection only when it sends a
request — so resolving connections in tests needs no running InfluxDB.

A test starts no Hypervel server, so no server version is read in advance:
the InfluxQL builder sends a `/ping` before its first statement on a
connection, and so does each connection of the `influxql` database driver. A
test that sends through a fake `httpClient` can answer it with the version in
an `X-Influxdb-Version` header. Or it sets the version first, any version of
the major the connection's `version` names: for the builder, on the manager's
InfluxQL connection; for the driver, on the InfluxQL connection each of its
database connections wraps, which is not the manager's:

```php
InfluxDB::influxql()->setServerVersion('1.8.10');                     // what an InfluxDB 1.8 server names
DB::connection('metrics')->getInfluxQL()->setServerVersion('1.8.10'); // an influxql database connection
```

On a connection that batches its writes, points a test leaves buffered are
sent as the test ends, when Hypervel's test harness wakes the flush timer
([Where the package keeps state](internals.md#where-the-package-keeps-state)):
give the test's transport a response for that request too.
