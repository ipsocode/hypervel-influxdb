# hypervel-influxdb

InfluxDB connections for [Hypervel](https://github.com/hypervel/components):
a connection manager, a facade and a publishable config on top of the official
[`influxdata/influxdb-client-php`](https://github.com/influxdata/influxdb-client-php),
an InfluxQL query builder for InfluxDB 1.x, 2.x and 3, database drivers that
run Hypervel's own query builder on InfluxQL, for any of them, and on SQL, for
InfluxDB 3, and a read-only Eloquent model of a measurement for either driver.

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

## What it provides

- **[Connections](docs/configuration.md#connections)**: `config/influxdb.php`
  becomes named, lazily created `InfluxDB2\Client` instances, handed out by
  [a manager](docs/hypervel.md#the-connection-manager), the `InfluxDB` facade
  and the container. Writing, Flux queries and the management services are
  the official client's, unchanged. The manager is built on Hypervel's own
  components, and the package requires nothing beyond Hypervel and the
  InfluxDB client.
- **[Writes](docs/writing.md#writing-through-writeapi)** through one
  `WriteApi` per connection per worker, and
  [batching](docs/writing.md#batching-writes) built for long-lived workers:
  size, megabyte and time limits, a drain when the worker exits, and failures
  reported rather than thrown.
- **[An InfluxQL query builder](docs/influxql.md#the-query-builder)** shaped
  like Hypervel's `Query\Builder`, compiled for the InfluxDB version the
  connection names.
- **[The `influxql` database driver](docs/influxql-driver.md#setting-up-the-influxql-driver)**,
  which runs Hypervel's own query builder on InfluxQL, on any InfluxDB version.
- **[SQL on InfluxDB 3](docs/sql.md#setting-up-the-influxdb-driver)**: the
  read-only `influxdb` database driver, with Hypervel's query builder, raw
  queries, joins, unions and window functions.
- **[Read-only Eloquent measurements](docs/eloquent.md#defining-a-measurement)**:
  `Measurement`, a model of a measurement with the settings a time series
  needs, on either driver.

## Requirements

- PHP 8.4 or newer (CI runs 8.4 and 8.5)
- Hypervel 0.4, which today means `hypervel/components` at `0.4.x-dev`. The
  package requires `hypervel/components` itself rather than its split packages.
- `influxdata/influxdb-client-php` `^3.9`, and a server it can talk to:
  InfluxDB 2.x, or InfluxDB 1.8+ or InfluxDB 3 Core or Enterprise through
  their 2.x compatibility API. The
  [InfluxQL query builder](docs/influxql.md#the-query-builder) and the
  [`influxql` database driver](docs/influxql-driver.md#setting-up-the-influxql-driver)
  run on any of them, compiled for the version the connection
  [names](docs/configuration.md#choosing-the-server-version), and
  [SQL](docs/sql.md#setting-up-the-influxdb-driver) runs on InfluxDB 3.

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

`url`, `token`, `bucket` and `org` are required, and `version` names the major
version of InfluxDB the server runs: `v1`, the default, `v2` or `v3`
([what each version changes](docs/configuration.md#choosing-the-server-version)).
[Configuration](docs/configuration.md) covers what each key takes on each
version, further connections and the options passed to the client.

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

[Writing points](docs/writing.md#writing-through-writeapi) explains why to write
through `writeApi()` rather than the client's `createWriteApi()`, and how to
[batch writes](docs/writing.md#batching-writes).

## Documentation

- [Configuration](docs/configuration.md): connections, the server version and what it changes, connecting to InfluxDB 1.x, 2.x and 3, and the options passed to the client.
- [Writing points](docs/writing.md): `writeApi()`, `RefPoint`, and batching writes in a long-lived worker.
- [Querying with InfluxQL](docs/influxql.md): the package's query builder, running statements, deleting points and configuring InfluxQL.
- [Hypervel's query builder over InfluxQL](docs/influxql-driver.md): the `influxql` database driver, on any InfluxDB version.
- [Querying InfluxDB 3 with SQL](docs/sql.md): the read-only `influxdb` database driver.
- [Models of measurements](docs/eloquent.md): `Measurement`, a read-only Eloquent model on either driver.
- [Running on Hypervel](docs/hypervel.md): long-lived workers, non-blocking HTTP, the connection manager and testing an application that uses the package.
- [Internals](docs/internals.md): for contributors, how the package is built and where it keeps state.

## Contributing

The development setup, the checks CI runs, the coroutine-safety rules every
change is held to, and how releases are cut are in
[CONTRIBUTING.md](CONTRIBUTING.md). Report security issues privately, as
described in [SECURITY.md](.github/SECURITY.md), rather than in a public issue.

## Credits

This package includes code adapted from
[`ge-tracker/influxdb-laravel`](https://github.com/ge-tracker/influxdb-laravel)
by James Austen ([GE Tracker](https://www.ge-tracker.com)),
[`graham-campbell/manager`](https://github.com/GrahamCampbell/Laravel-Manager)
by Graham Campbell, and
[`hypervel/database`](https://github.com/hypervel/components/tree/0.4/src/database)
by [Hypervel](https://github.com/hypervel/components), which includes code
from Taylor Otwell's Laravel. It talks to InfluxDB through
[InfluxData](https://www.influxdata.com)'s
[`influxdata/influxdb-client-php`](https://github.com/influxdata/influxdb-client-php).

## License

MIT. See [LICENSE](LICENSE), which carries the copyright notices of this
package and of the code it includes.
