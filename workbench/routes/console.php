<?php

declare(strict_types=1);

use Hypervel\Support\Facades\Artisan;
use InfluxDB2\Point;
use Ipsocode\InfluxDB\InfluxDBManager;
use Ipsocode\InfluxDB\Support\RefPoint;

/*
|--------------------------------------------------------------------------
| Workbench Console Routes
|--------------------------------------------------------------------------
|
| Commands standing in for those an application would write against this package,
| so the suite drives the manager through the console too. Registered by
| Workbench::discoverCommandsRoutes(), gated on `workbench.discovers.commands` in
| testbench.yaml. None touches the network: InfluxDB2\Client opens no socket when
| it is constructed, so they run in CI with no InfluxDB server in reach.
|
*/

Artisan::command('influxdb:connections', function (InfluxDBManager $influxdb): int {
    $default = $influxdb->getDefaultConnection();

    $rows = array_map(
        function (string $name) use ($influxdb, $default): array {
            $config = $influxdb->getConnectionConfig($name);

            return [
                $name . ($name === $default ? ' (default)' : ''),
                $config['url'] ?? '',
                $config['bucket'] ?? '',
                $config['org'] ?? '',
            ];
        },
        array_keys((array) config('influxdb.connections', [])),
    );

    $this->table(['Connection', 'URL', 'Bucket', 'Org'], $rows);

    return 0;
})->purpose('List the configured InfluxDB connections');

Artisan::command('influxdb:show {connection?}', function (InfluxDBManager $influxdb): int {
    $name = (string) ($this->argument('connection') ?: $influxdb->getDefaultConnection());

    try {
        $config = $influxdb->getConnectionConfig($name);
    } catch (InvalidArgumentException $exception) {
        $this->error($exception->getMessage());

        return 1;
    }

    // Resolved only to exercise the factory and the client cache under this name.
    $influxdb->connection($name);

    $this->info("InfluxDB connection [{$name}]");

    foreach (['url', 'bucket', 'org'] as $key) {
        $this->line(sprintf('  %-8s %s', $key . ':', $config[$key] ?? '-'));
    }

    return 0;
})->purpose('Resolve one InfluxDB connection and show its config');

Artisan::command('influxdb:preview {measurement} {--tag=*} {--field=*}', function (): int {
    $point = Point::measurement((string) $this->argument('measurement'));

    foreach (['tag' => 'addTag', 'field' => 'addField'] as $option => $method) {
        foreach ((array) $this->option($option) as $pair) {
            if (! str_contains((string) $pair, '=')) {
                $this->error("Malformed --{$option}=[{$pair}], expected --{$option}=key=value.");

                return 1;
            }

            [$key, $value] = explode('=', (string) $pair, 2);

            // Cast numeric fields so the preview shows the type that would be written.
            $point->{$method}($key, $method === 'addField' && is_numeric($value) ? $value + 0 : $value);
        }
    }

    // RefPoint reads back the private state Point never exposes.
    $ref = RefPoint::from($point);

    $this->info("Measurement: {$ref->getMeasurement()}");

    foreach (['Tag' => $ref->getTags(), 'Field' => $ref->getFields()] as $label => $values) {
        foreach ($values ?? [] as $key => $value) {
            $this->line(sprintf('  %-6s %s = %s', $label . ':', $key, var_export($value, true)));
        }
    }

    return 0;
})->purpose('Build a point and show what would be written, without writing it');

Artisan::command('influxdb:query {measurement} {--connection=} {--select=*} {--where=*} {--group-by-time=} {--limit=}', function (InfluxDBManager $influxdb): int {
    try {
        $query = $influxdb->table((string) $this->argument('measurement'), $this->option('connection') ?: null);

        foreach ((array) $this->option('select') as $column) {
            $query->addSelect((string) $column);
        }

        foreach ((array) $this->option('where') as $pair) {
            if (! str_contains((string) $pair, '=')) {
                $this->error("Malformed --where=[{$pair}], expected --where=key=value.");

                return 1;
            }

            [$key, $value] = explode('=', (string) $pair, 2);

            // Cast numeric values, as in influxdb:preview, so the statement compares a number.
            $query->where($key, is_numeric($value) ? $value + 0 : $value);
        }

        if ($this->option('group-by-time')) {
            $query->groupByTime((string) $this->option('group-by-time'));
        }

        if ($this->option('limit') !== null) {
            $query->limit((int) $this->option('limit'));
        }
    } catch (InvalidArgumentException $exception) {
        $this->error($exception->getMessage());

        return 1;
    }

    // Compile only: toRawSql() is the statement exactly as the connection would send it.
    $this->line($query->toRawSql());

    return 0;
})->purpose('Compile an InfluxQL query and print the statement, without running it');
