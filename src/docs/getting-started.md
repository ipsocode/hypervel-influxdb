# Getting started

This page covers installing the package, what it registers without any setup,
and reaching a connection through the `InfluxDB` facade or the container.

## Installing

```sh
composer require ipsocode/hypervel-influxdb
php artisan vendor:publish --tag=influxdb-config
```

Hypervel 0.4 is only available as a dev branch, so your application's
`composer.json` must already allow it: `"minimum-stability": "dev"` together
with `"prefer-stable": true`.

Tags are not re-tested as `0.4.x-dev` moves on. `main` is: a scheduled
workflow re-tests it each night that `0.4.x-dev` has moved, and while `main`
fails against the current `0.4.x-dev`, an issue titled "Nightly components
check failed" is open in the package's
[issues](https://github.com/ipsocode/hypervel-influxdb/issues). To pick up
changes as they land, require `ipsocode/hypervel-influxdb:dev-main` instead.
Each release's notes, breaking changes first, are on the
[Releases](https://github.com/ipsocode/hypervel-influxdb/releases) page.

## What is registered for you

The service provider (`Ipsocode\InfluxDB\InfluxDBServiceProvider`) and the
`InfluxDB` alias are discovered through the package's `extra.hypervel` block,
so there is nothing to register. Publishing the config is optional: without it
the package's own `config/influxdb.php` applies, driven by the environment
variables listed under [Connections](configuration.md#connections).

## Using a connection

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

The container bindings are `influxdb` (the manager, aliased to
`InfluxDBManager`), `influxdb.factory` (aliased to `InfluxDBFactory`) and
`influxdb.connection` (the default connection, aliased to `InfluxDB2\Client`).

Each connection is an `InfluxDB2\Client`, so writing, Flux queries and the
management services are the official client's, unchanged: a method the
manager does not define goes to the default connection's client (see
[What it keeps](hypervel.md#what-it-keeps)).
[Writing points](writing.md#writing-through-writeapi) explains why to write
through `writeApi()` rather than the client's `createWriteApi()`, and how to
[batch writes](writing.md#batching-writes).
