<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Workbench InfluxDB Connections
|--------------------------------------------------------------------------
|
| The Workbench application's `config/influxdb.php`, as an application would publish
| it. Testbench loads it before the service provider registers.
|
| `default` is left out so it still arrives from the package's own config through
| the provider's `mergeConfigFrom()`, which keeps that merge under test. The values
| are literals, not env(): `testbench.yaml`'s env vars apply under the Testbench CLI
| only, so env() here would be null under `composer test:phpunit`. Literals keep
| both runners in agreement.
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

        // A second connection for the manager's multi-connection tests; its optional
        // keys are left unset so the factory's own defaults stay covered.
        'analytics' => [
            'url' => 'http://analytics.localhost:8086',
            'token' => 'analytics-token',
            'bucket' => 'analytics-bucket',
            'org' => 'analytics-org',
        ],
    ],
];
