# Models of measurements

`Ipsocode\InfluxDB\Eloquent\Measurement` is a read-only Eloquent model of a
measurement. This page shows how to define one, the settings it makes for a
time series, and how to give it a builder of its own.

## Defining a measurement

A measurement runs on either database driver:
[`influxql`](influxql-driver.md#setting-up-the-influxql-driver), on any
InfluxDB version, or [`influxdb`](sql.md#setting-up-the-influxdb-driver), on
InfluxDB 3. Extend it with the connection and the measurement it reads:

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

On an `influxdb` connection, a measurement reads an InfluxDB 3 database
through SQL. That driver has no `epoch`, so `time` comes back as a string and
can be cast, within the limits [below](#the-settings-a-time-series-needs):

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

InfluxDB 3 returns `time` in UTC without an offset (see
[What to expect](sql.md#what-to-expect)), and the cast reads such a string in
the application's time zone: keep `app.timezone` at UTC, Hypervel's default,
or the instant is off by that zone's offset.

A query of the model is compiled and sent by its connection's driver, so it
can say what that driver's builder can: see What to expect for
[`influxql`](influxql-driver.md#what-to-expect) and for
[`influxdb`](sql.md#what-to-expect).

## The settings a time series needs

`Measurement` makes the settings a time series needs:

- **A point is keyed by its `time`.** The key is the timestamp as the server
  returns it, never incremented. `find()` and `whereKey()` compare `time`,
  and `latest()` and `oldest()` order by it. `time` is not unique, though:
  several series can each hold a point at the same time, and `find()` returns
  one of them. InfluxQL answers an `OR` between times with no points, so on
  the `influxql` driver `findMany()`, and `whereKey()` given several keys,
  find none.
- **It writes nothing.** InfluxDB takes points through its write API rather
  than a query, so `save()` and `delete()` throw a `LogicException`, and with
  them `create()`, `push()`, `destroy()`, `forceDelete()`, their quiet
  variants and `update()` on a model read from the server. Each throws
  before anything is sent, except `destroy()`, which first reads the points
  it names and throws on the first it finds. Write points through
  [`InfluxDB::writeApi()`](writing.md#writing-through-writeapi). A statement
  the builder sends itself is the connection's to refuse:
  `Cpu::query()->update([...])` throws, but
  `Cpu::query()->where('host', 'web1')->delete()` deletes the points on
  InfluxDB 1.x, as `delete()` does on the
  [`influxql` driver's builder](influxql-driver.md#what-to-expect);
  [Deleting points](influxql.md#deleting-points) says what the other versions
  allow. On the `influxdb` driver it throws, as every write there does.
- **It keeps no timestamps, and guards nothing**, since every attribute comes
  from the server.
- **Relations load through queries of their own.** `with()` and lazy access,
  such as `$cpu->mem`, work on either driver. On `influxql`, `whereHas()`,
  `has()` and `withCount()` need a sub-select, and are refused.
- **`value()` reads the value it asked for.** InfluxQL returns every point
  with its `time` first, so given an expression, such as
  `Cpu::query()->value(DB::raw('mean("usage_user")'))`, `value()`,
  `soleValue()` and `valueOrFail()` return the first attribute after `time`,
  under whatever name the server gave it. SQL returns the expression on its
  own. Either way, the value is read through the model's casts and accessors.
- **`series()`** returns the points as the server grouped them, with the
  query's scopes applied: a query grouped by tags returns one series per
  combination of their values, which carries the tags once rather than on
  each model. The series are the server's, as `series()` on the
  [`influxql` driver's builder](influxql-driver.md#what-to-expect) returns
  them, so no cast or accessor reads them. It needs the `influxql` driver, and
  throws a `LogicException` on `influxdb`.
- **`time` is not cast.** A `datetime` cast makes the key an object, which
  `chunkById()` and `lazyById()` cannot page on. It also reads an integer as
  seconds, whatever precision the connection's
  [`epoch`](influxql.md#configuring-influxql) returns. Cast `time`, as
  `protected array $casts = ['time' => 'datetime'];`, only on a connection
  without `epoch`, and page with `chunk()`, `lazy()` or `cursorPaginate()`.
  On `influxql`, `chunkById()` and `lazyById()` are refused either way: their
  first page asks for a `time` that is not null, which InfluxQL cannot say.

## Custom builders

Every measurement queries through `Ipsocode\InfluxDB\Eloquent\Builder`, which
`Cpu::query()` returns: Hypervel's Eloquent builder with `series()` added and
`value()` read as [above](#the-settings-a-time-series-needs).

A measurement can name a builder of its own with `#[UseEloquentBuilder]`, as
any Hypervel model can. That builder has to extend
`Ipsocode\InfluxDB\Eloquent\Builder`, or `newQuery()`, and with it
`Cpu::query()`, throws a `LogicException`:

```php
use Hypervel\Database\Eloquent\Attributes\UseEloquentBuilder;
use Ipsocode\InfluxDB\Eloquent\Builder;
use Ipsocode\InfluxDB\Eloquent\Measurement;
use UnitEnum;

class CpuBuilder extends Builder
{
    public function whereHost(string $host): static
    {
        return $this->where('host', $host);
    }
}

#[UseEloquentBuilder(CpuBuilder::class)]
class Cpu extends Measurement
{
    protected UnitEnum|string|null $connection = 'influxdb';

    protected ?string $table = 'cpu';
}

$web1 = Cpu::query()->whereHost('web1')->latest()->first();
```

Name the builder with the attribute: a measurement does not read the static
`$builder` property that a Hypervel model can also set.
