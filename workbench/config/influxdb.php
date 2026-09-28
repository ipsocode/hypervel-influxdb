<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Workbench InfluxDB Connections
|--------------------------------------------------------------------------
|
| The Workbench application's copy of the package config, standing in for the
| `config/influxdb.php` a consuming application would publish. Testbench loads
| this over Hypervel's defaults before the service provider registers, so the
| suite resolves connections the same way a real application does.
|
| Only `connections` is defined here. `default` is deliberately left out so it
| still arrives from the package's own `config/influxdb.php` through the
| provider's `mergeConfigFrom()` call — which is what keeps that merge under
| test rather than masked by a full copy of the config.
|
| The values are literals rather than env() lookups on purpose. `testbench.yaml`
| env vars are applied by the Testbench CLI only, not by the TestCase path, so
| an env-driven fixture here would resolve under `composer test` and come back
| null under `composer test:phpunit`. Literals keep both runners in agreement.
| The env-driven shape of the published config is still exercised:
| `influxdb.default` reaches the suite via env('INFLUXDB_CONNECTION', 'main') in
| the package's own config file.
|
*/

return [
    'connections' => [
        'main' => [
            'url' => 'http://localhost:8086',
            'token' => 'main-token',
            'bucket' => 'main-bucket',
            'org' => 'main-org',
        ],

        // A second connection, so the manager's multi-connection behaviour
        // (distinct instances, per-name caching, disconnect/reconnect) has
        // something to resolve. Its optional keys are left unset so the
        // factory's own defaults stay covered.
        'analytics' => [
            'url' => 'http://analytics.localhost:8086',
            'token' => 'analytics-token',
            'bucket' => 'analytics-bucket',
            'org' => 'analytics-org',
        ],
    ],
];
