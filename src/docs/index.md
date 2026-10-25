# hypervel-influxdb documentation

These pages cover installing and configuring the package, writing points,
reading them back with InfluxQL or SQL, and what running on Hypervel's
long-lived workers changes. Read them in the order below, starting with
Getting started.

## Setting up

- [Getting started](getting-started.md): installing the package, what it registers without any setup, and reaching a connection through the facade or the container.
- [Configuration](configuration.md): connections, the server version and what it changes, connecting to InfluxDB 1.x, 2.x and 3, and the options passed to the client.

## Writing and querying

- [Writing points](writing.md): `writeApi()`, `RefPoint`, falling back to other connections, and batching writes in a long-lived worker.
- [Querying with InfluxQL](influxql.md): the package's query builder, running statements, deleting points and configuring InfluxQL.
- [Hypervel's query builder over InfluxQL](influxql-driver.md): the `influxql` database driver, on any InfluxDB version.
- [Querying InfluxDB 3 with SQL](sql.md): the read-only `influxdb` database driver.
- [Models of measurements](eloquent.md): `Measurement`, a read-only Eloquent model on either driver.

## Running on Hypervel

- [Running on Hypervel](hypervel.md): long-lived workers, non-blocking HTTP, the connection manager, `php artisan about` and testing an application that uses the package.

To work on the package itself, see [CONTRIBUTING.md](https://github.com/ipsocode/hypervel-influxdb/blob/main/CONTRIBUTING.md).
