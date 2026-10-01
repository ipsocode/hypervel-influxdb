# Writing points

This page covers writing points through the manager's `writeApi()`, reading a
built point back with `RefPoint`, and the batching writer a connection can
turn on to buffer its points in the worker and send them in batches.

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
throws an `InfluxDB2\ApiException` when it still fails.

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
to 3 where the client's default for a synchronous write is 5. A batch that
still fails is dropped, and never thrown at a `write()`, since the write that
filled it carries mostly other writes' points. Instead it is:

- reported through the application's exception handler as an
  `Ipsocode\InfluxDB\Write\BatchWriteException`, which names the connection,
  the batch's size and bucket, and the server's error, and whose previous
  exception is the failure, usually the client's `InfluxDB2\ApiException`;
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
