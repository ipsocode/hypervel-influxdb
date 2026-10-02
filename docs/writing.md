# Writing points

This page covers writing points through the manager's `writeApi()`, reading a
built point back with `RefPoint`, falling back to other connections when one
cannot take a write, and the batching writer a connection can turn on to
buffer its points in the worker and send them in batches.

## Writing through `writeApi()`

```php
use InfluxDB2\Point;
use Ipsocode\InfluxDB\Facades\InfluxDB;

InfluxDB::writeApi()->write(                 // the default connection's
    Point::measurement('cpu')->addTag('host', 'web1')->addField('load', 0.64)
);

InfluxDB::writeApi('analytics')->write([     // a named connection's
    Point::measurement('cpu')->addTag('host', 'web1')->addField('load', 0.64),
    Point::measurement('cpu')->addTag('host', 'web2')->addField('load', 0.31),
]);
```

Write through `writeApi()`, on the facade or on an injected `InfluxDBManager`
(see [Usage](../README.md#usage)). It keeps one `WriteApi` per connection for
the life of the worker. The client's own `createWriteApi()` is reachable
through the facade and the manager too, but the client keeps a reference to
every `WriteApi` it creates, each holding an HTTP client, so calling it on
each request leaks one per request until the worker exits.

`write()` takes what the client's `InfluxDB2\WriteApi` takes: an
`InfluxDB2\Point`, a point as an array with `name`, `tags`, `fields` and
`time` keys, a string of line protocol, or an array of any of them, which a
synchronous `WriteApi` sends in one request. `writeRaw()` takes line
protocol. Both take a precision, a bucket and an org after the data,
which default to the connection's `precision`, `bucket` and `org`. To buffer
points and send them in batches instead, see [Batching writes](#batching-writes).

A synchronous write, the default, is retried by the client on a status of
`429` or above or a network error, up to its `maxRetries`, 5 by default, and
throws an `InfluxDB2\ApiException` when it still fails, unless the connection
names [fallbacks](#falling-back-to-other-connections) to write through instead.

`writeApi()` builds the `WriteApi` from the connection's `write` block (see
[Passing options to the client](configuration.md#passing-options-to-the-client)).
Write options given as its second argument are used instead, but only by the
connection's first call in the worker: later calls return the `WriteApi`
already built, and ignore them. A connection that is not configured throws an
`InvalidArgumentException`. How the manager keeps the `WriteApi`, and what
`disconnect()` does to it, is in
[The connection manager](hypervel.md#the-connection-manager).

## Reading points back with `RefPoint`

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
`RefPoint`s. The getters read the point `from()` was given, so they show what
is added to that point afterwards. A `RefPoint` made with `new`, as a `Point`
is made, reads its own measurement, tags and fields:

```php
$ref = new RefPoint('cpu', ['host' => 'web1'], ['load' => 0.64]);

$ref->getTags(); // ['host' => 'web1']
```

`getTags()` and `getFields()` return `null` for a point that has none. The
reflection is cached in a static, which tests reset as
[Testing an application that uses it](hypervel.md#testing-an-application-that-uses-it)
describes.

## Falling back to other connections

A connection can name other connections to write through when it cannot take
a write itself, such as a second server kept for when the first is down:

```php
'connections' => [
    'main' => [
        // url, token, bucket, org, ...
        'write' => [
            'fallback' => ['backup'], // or 'backup', or 'backup,archive' as env() reads it
            'cooldown' => 30,         // seconds a connection that failed is skipped
        ],
    ],
    'backup' => [
        // url, token, bucket, org, ... of the server the points go to meanwhile
    ],
],
```

The package's config reads `INFLUXDB_FALLBACK`, connection names separated by
commas, and `INFLUXDB_COOLDOWN` into `main`'s `write` block. `writeApi()` then
returns an `Ipsocode\InfluxDB\Write\FailoverWriter`, a synchronous `WriteApi`
whose writes fall back, or, with [batching](#batching-writes) on, a
`BatchingWriter` whose batches do. Either writes through the connection first,
with the client's retries, and only when the connection cannot take the write
tries each fallback in order, with the same retries, until one takes it. The
`write` options given to `writeApi()` may name a `fallback` too.

### What is failed over

A connection fails to take a write when its server cannot be reached, or
answers `429` or a `5xx` once the client's retries are spent: the failures
the client retries. A write the server refuses for the write's own fault,
with any other `4xx`, such as a `400` for a line it cannot parse, is not
failed over: it is refused as it would be without fallbacks, since a fallback
would refuse it too, and the connection is not taken for down. A fallback
that refuses a write is passed over, and the next one tried.

A write for the connection's own `bucket` and `org` goes to the fallback's
own `bucket` and `org`, so the two servers may name them differently. A write
addressed to another bucket or org, through the arguments of `write()` or
`writeRaw()`, keeps them on every connection, and the precision is kept
everywhere.

### Cooldowns

A connection that failed to take a write is skipped for `cooldown` seconds,
30 unless set, so the writes made meanwhile go straight to the next connection
rather than wait out the client's retries on each. Once the cooldown is over
it is tried again. Zero turns cooldowns off: every write tries every
connection in order. The cooldowns are one set per worker, shared by every
connection's writer, so a connection one writer finds down is skipped by the
others, and the manager's `availability()` reads them:

```php
InfluxDB::availability()->isAvailable('main');    // false while it is cooling down
InfluxDB::availability()->unavailableFor('main'); // the seconds left, or null
InfluxDB::availability()->unavailable();          // every connection cooling down, with its seconds left
```

`markUnavailable($name, $seconds)` and `markAvailable($name)` set them, for a
health check or a test; like `disconnect()`, they change what every coroutine
on the worker sees.

### What is reported, and what is thrown

When a fallback takes a write after a connection failed, the failure is
reported through the application's exception handler as an
`Ipsocode\InfluxDB\Write\FailoverException` naming what each connection did
and which took the write:
`InfluxDB connection [main] failed to write 500 points (41234 bytes) for bucket [metrics], which connection [backup] took: [main] [503] Error connecting to the API (…)(service unavailable).`
Its previous exception is the connection's own failure, the client's
`InfluxDB2\ApiException`, its `takenBy` property names the fallback, and its
`failures()` and `skipped()` return the failure of each connection tried and
the seconds left of each skipped. A write that only skipped connections
cooling down, with no new failure, is not reported.

When no connection takes a write, a synchronous write throws the
`FailoverException`, a `RuntimeException` rather than the client's
`ApiException`, whose message ends
`…, and none of its fallbacks took them: [main] [503] … (…); [backup] skipped for another 12.3 seconds.`,
and a batch is dropped as [one that cannot be written](#when-a-batch-cannot-be-written):
reported, and handed to `onFailure`, as a `BatchWriteException` whose
previous exception is the `FailoverException`. A write made while every
connection is cooling down is refused or dropped the same way, without a
request.

### What to expect

- A fallback is written through a synchronous `WriteApi` of its own, built on
  that connection's client options when first needed and kept for the
  worker's life, with the write options of the writer it serves, `maxRetries`
  and the other retry options included. The fallback connection's own `write`
  block, its batching or its fallbacks, does not apply to a write that falls
  back to it, so a failover never chains from one connection to the next.
- Each fallback's client is built when `writeApi()` first builds the writer,
  so a fallback that is not configured, or missing a required key, throws an
  `InvalidArgumentException` then, naming both connections, such as
  `InfluxDB connection [main] has an invalid write.fallback [backup]; expected the name of a configured connection: InfluxDB connection [backup] is not configured.`
  A connection falling back to itself, and a `fallback` or `cooldown` of the
  wrong kind, throw one too.
- A write that falls back takes longer: the connection's retries, then each
  fallback's. A batching connection sends from coroutines of its own, so a
  request or job waits for none of it; a synchronous `write()` waits for all
  of it.
- Points written to a fallback are on that server, not the connection's own:
  a query of the connection does not see them. Fallbacks suit writes that must
  land somewhere, such as metrics and events, with the servers reconciled
  afterwards or read together.

## Batching writes

By default each `write()` is one request, sent before `write()` returns.
InfluxData recommends writing in batches instead: fewer, larger requests. A
connection whose `writeType` is `WriteType::BATCHING`, the client's own
switch, buffers its points in the worker and sends them in batches:

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

The package's config turns it on for `main` with `INFLUXDB_BATCHING=true`, and
reads the limits from `INFLUXDB_BATCH_SIZE`, `INFLUXDB_BATCH_SIZE_MB` and
`INFLUXDB_FLUSH_INTERVAL`. `writeApi()` then returns an
`Ipsocode\InfluxDB\Write\BatchingWriter`: a `WriteApi` whose `write()` and
`writeRaw()` buffer rather than send, so code written against a synchronous
one carries over. It serialises each point as a synchronous `WriteApi` would,
default `tags`, precision, bucket and org included, and sends each batch as a
synchronous write, which is why its `writeOptions` report
`WriteType::SYNCHRONOUS`: tell it apart with `instanceof BatchingWriter`.

The `write` block takes these options for batching:

| Option | Default | What it sets |
|---|---|---|
| `writeType` | `WriteType::SYNCHRONOUS` | `WriteType::BATCHING` turns batching on |
| `batchSize` | [By `version`](#when-a-batch-is-sent) | The lines of line protocol a batch is sent at |
| `batchSizeMb` | [By `version`](#when-a-batch-is-sent) | The megabytes a batch is sent at |
| `flushInterval` | `1` | The seconds after the buffer's first point that it is sent at the latest |
| `maxBuffered` | 10 × `batchSize` | The points a writer [holds](#how-much-a-worker-holds), buffered or being sent |
| `overflow` | `'flush'` | What a write past `maxBuffered` does: `'flush'` or `'refuse'` (`BatchOptions::FLUSH`, `BatchOptions::REFUSE`) |
| `onFailure` | None | A callable, or the name of an invokable class, handed each batch that [cannot be written](#when-a-batch-cannot-be-written) |
| `maxRetries` | `3` | The client's option: the retries a batch gets |
| `fallback` | None | The connections a batch this one cannot take [falls back to](#falling-back-to-other-connections), in order |
| `cooldown` | `30` | The seconds a connection that failed to take a batch is skipped |

The client's other retry options, `retryInterval`, `maxRetryDelay`,
`maxRetryTime`, `exponentialBase` and `jitterInterval`, apply to each batch as
they do to a synchronous write. An option left out or `null` takes its
default, and numbers may be given as the strings `env()` reads. `batchSize`
and `maxBuffered` must be whole numbers above zero, and `batchSizeMb` and
`flushInterval` numbers above zero, so `0.5` will do. An option out of range,
or an `overflow` or `onFailure` of the wrong kind, throws an
`InvalidArgumentException` naming the connection and the option when
`writeApi()` first builds the writer, such as
`InfluxDB connection [main] has an invalid write.batchSize [0]; expected a whole number above zero.`,
and so does a `version` the package does not implement. The writer's
`getBatchOptions()` returns the options it runs with, `batchSizeMb` turned into
bytes as `batchBytes`.

How the writer stays correct while many coroutines write at once is described
for contributors in [The batching writer](internals.md#the-batching-writer).

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
  coordinator, which wakes it early, and a write made while the worker exits
  is sent at once, without waiting for a timer;
- when a console command finishes, and when the console application
  terminates, but not when an HTTP request ends, so no response waits on a
  flush (see [The connection manager](hypervel.md#the-connection-manager)).
  A queue worker is a console command too, so this happens when the worker
  stops, not after each job;
- when the connection is [disconnected](hypervel.md#the-connection-manager).

`flush()` and `flushAll()` have nothing to do for a connection that writes
synchronously or has not written yet in the worker, and a batch that fails
during a flush is reported and handed to `onFailure` like any other, not
thrown. The client's `close()` does not reach a `BatchingWriter`, which the
client does not hold; the writer's own `close()` flushes it.

Left unset, `batchSize` and `batchSizeMb` follow InfluxData's advice for the
connection's [`version`](configuration.md#choosing-the-server-version):

| `version` | `batchSize` | `batchSizeMb` | From InfluxData's docs |
|---|---|---|---|
| `v1` | 5,000 lines | 25 | 1.x recommends [5,000 to 10,000 points per batch](https://docs.influxdata.com/influxdb/v1/concepts/glossary/#batch), and refuses a request body over [25 MB](https://docs.influxdata.com/influxdb/v1/administration/config/#max-body-size) by default |
| `v2` | 5,000 lines | 50 | [5,000 lines is the optimal batch size](https://docs.influxdata.com/influxdb/v2/write-data/best-practices/optimize-writes/#batch-writes) on 2.x; [InfluxDB Cloud](https://docs.influxdata.com/influxdb/cloud/account-management/limits/) refuses a request over 50 MB, and OSS 2.x sets no limit |
| `v3` | 10,000 lines | 10 | [10,000 lines or 10 MB, whichever threshold is met first](https://docs.influxdata.com/influxdb3/core/write-data/best-practices/optimize-writes/#batch-writes), which is also within InfluxDB 3's default `max-http-request-size` |

A megabyte is 1,000,000 bytes, which keeps `batchSizeMb` under a server's
limit whichever way the server counts. InfluxData's docs set no time limit;
`flushInterval` defaults to 1 second on every version.

### When a batch cannot be written

A batch is retried as a synchronous write is, on a status of `429` or above or
a network error, with the client's backoff, except that `maxRetries` defaults
to 3 where the client's default for a synchronous write is 5. On a connection
with [fallbacks](#falling-back-to-other-connections), a batch the connection
still cannot take goes to the first fallback that can. A batch that still
fails is dropped, and never thrown at a `write()`, since the write that
filled it carries mostly other writes' points. Instead it is:

- reported through the application's exception handler as an
  `Ipsocode\InfluxDB\Write\BatchWriteException`, which names the connection,
  the batch's size and bucket, and the server's error, and whose previous
  exception is the failure: usually the client's `InfluxDB2\ApiException`, or
  the `FailoverException` naming what each connection did when fallbacks
  were tried;
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

The exception's message reads like
`InfluxDB connection [main] dropped a batch of 5000 points (412345 bytes) for bucket [my-bucket]: …`,
ending with the server's error, and its `batch` property is the `Batch`. A
`Batch` also carries the `connection` that buffered it, its `points` (the
lines in its payload) and `bytes()`, the payload's size. Writing it again is
`writeRaw($batch->payload, $batch->precision, $batch->bucket, $batch->org)` on
that connection's `WriteApi`.

`onFailure` runs in the coroutine that sent the batch, seldom that of a
request that wrote its points, so it cannot rely on request state.
The batch's points count toward `maxBuffered` until it returns, and `flush()`
and the worker's exit wait for it, so keep it quick: hand the payload to
storage or a queue rather than retrying the server from it. An exception it
throws is reported through the exception handler too, and an exception the
handler cannot report goes to PHP's `error_log()`.

### How much a worker holds

`maxBuffered` caps the points a writer holds, buffered or being sent, at ten
batches' worth by default: 10 × `batchSize`. A write that would take it past
the cap sends the buffer itself before it returns, so while the server falls
behind, writes slow to its pace as synchronous ones would. With
`'overflow' => 'refuse'`, such a write throws an
`Ipsocode\InfluxDB\Write\BufferFullException` instead, and keeps none of the
points it refuses. The writer's `getPendingPoints()` counts what it holds.

A string of line protocol is refused whole, but a list of points is buffered
point by point: when a point in a list is refused, the points ahead of it are
kept and those after it are not written. `BufferFullException` is an
`OverflowException` whose `connection`, `points` (the points refused),
`waiting` and `maxBuffered` properties say what happened, as its message does:
`InfluxDB connection [main] refused 1 point: 50000 are waiting to be sent, and its maxBuffered is 50000.`

### What batching does not solve

Points wait in one worker's memory until they are sent. A graceful exit sends
them, within the `max_wait_time` Hypervel gives a stopping worker, 3 seconds by
default, but a crash, an out-of-memory kill or a `SIGKILL` loses them. Batch
data that can bear the loss, such as metrics, and keep writing what cannot
synchronously, or from a queued job.

Code that writes outside a coroutine, such as a task worker without
`task_enable_coroutine`, has no flush timer and no coroutine to send from: a
batch that fills is sent by the write that filled it, which waits for it and
its retries, and the buffer is sent by the first write after `flushInterval`,
so call `flush()` when that work is done.
