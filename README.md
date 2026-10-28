# hypervel-influxdb

InfluxDB for [Hypervel](https://github.com/hypervel/components): a connection
manager and facade over the official client, write batching and fallbacks, an
InfluxQL query builder, database drivers for InfluxQL and InfluxDB 3's SQL,
and read-only Eloquent models of measurements.

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

## Requirements

- PHP 8.4 or newer (CI runs 8.4 and 8.5)
- Hypervel 0.4, which today means `hypervel/components` at `0.4.x-dev`. The
  package requires `hypervel/components` itself rather than its split packages.
- `influxdata/influxdb-client-php` `^3.9`, and a server it can talk to:
  InfluxDB 2.x, or InfluxDB 1.8+ or InfluxDB 3 Core or Enterprise through
  their 2.x compatibility API
  ([what each version changes](src/docs/configuration.md#choosing-the-server-version)).

## Installation

```sh
composer require ipsocode/hypervel-influxdb
php artisan vendor:publish --tag=influxdb-config
```

Hypervel 0.4 is only available as a dev branch, so your application's
`composer.json` must allow it; [Getting started](src/docs/getting-started.md#installing)
says how. The default connection reads the
[`INFLUXDB_*` environment variables](src/docs/configuration.md#connections), and
Getting started covers tracking `dev-main`,
what is registered for you, and the container.

## Usage

```php
use InfluxDB2\Point;
use Ipsocode\InfluxDB\Facades\InfluxDB;

InfluxDB::writeApi()->write(
    Point::measurement('cpu')->addTag('host', 'web1')->addField('load', 0.64)
);

$load = InfluxDB::table('cpu')->where('host', 'web1')->avg('load');
```

## Documentation

[The documentation](src/docs/index.md) lists every page in reading order,
starting with [Getting started](src/docs/getting-started.md);
[Running on Hypervel](src/docs/hypervel.md) covers long-lived workers.

## Contributing

The development setup, the checks CI runs, the coroutine-safety rules every
change is held to, and how releases are cut are in
[CONTRIBUTING.md](https://github.com/ipsocode/hypervel-influxdb/blob/main/CONTRIBUTING.md).

## Security

Report security issues privately, as described in
[SECURITY.md](https://github.com/ipsocode/hypervel-influxdb/blob/main/.github/SECURITY.md), rather than in a public issue.

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
