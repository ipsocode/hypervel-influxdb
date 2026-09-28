This package, hypervel-influxdb, gives Hypervel 0.4 (PHP 8.4+, Swoole) InfluxDB
connections on top of influxdata/influxdb-client-php: a connection manager, a facade and
a publishable config; an InfluxQL query builder for InfluxDB 1.x, 2.x and 3; database
drivers that run Hypervel's own query builder on InfluxQL, and on SQL for InfluxDB 3; a
read-only Eloquent model of a measurement; and a batching writer.

In src/, in order:
1. The query that reaches InfluxDB: identifiers quoted, values bound rather than
   embedded, and anything written as-is (durations, literals) checked first. The
   builders' raw API is excepted from the conventions check on exactly that condition
   (see .github/conventions.php): flag a raw call that embeds an unchecked value. Also
   how connection config is resolved, merged and forwarded to InfluxDB2\Client, and
   what the drivers and the Eloquent model read back.
2. Coroutine safety: new static state without a flushState() reached from
   src/Testing/TestState.php; a WriteApi or QueryApi created per call on a request path
   (the upstream Client keeps every WriteApi it creates); worker-global config writes
   outside boot; blocking calls on a request path.
3. Tests construct clients without a live InfluxDB and send nothing over the network.
4. Security: TLS verification weakened by default, and tokens reaching logs, exception
   messages or console output, the workbench's commands included.
