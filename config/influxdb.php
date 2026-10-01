<?php

declare(strict_types=1);

use InfluxDB2\WriteType;

return [
    /*
    |--------------------------------------------------------------------------
    | Default Connection Name
    |--------------------------------------------------------------------------
    |
    | The connection the manager and the InfluxDB facade use when none is named.
    |
    */

    'default' => env('INFLUXDB_CONNECTION', 'main'),

    /*
    |--------------------------------------------------------------------------
    | InfluxDB Connections
    |--------------------------------------------------------------------------
    |
    | The connections, by name; each needs a url, token, bucket and org.
    | https://github.com/ipsocode/hypervel-influxdb/blob/main/docs/configuration.md#connections
    |
    */

    'connections' => [
        'main' => [
            // The server's major version: v1 (1.8+, which serves the 2.x write API), v2 or v3.
            // InfluxQL compiles for it and checks it against the server's; SQL needs v3.
            'version' => env('INFLUXDB_VERSION', 'v1'),
            'url' => env('INFLUXDB_URL'),
            'token' => env('INFLUXDB_TOKEN'),
            'bucket' => env('INFLUXDB_BUCKET'),
            'org' => env('INFLUXDB_ORG'),
            'verifySSL' => env('INFLUXDB_VERIFY_SSL', true),
            'precision' => env('INFLUXDB_PRECISION', 'ns'),
            'debug' => env('INFLUXDB_DEBUG', false),

            // InfluxQL addresses a database and retention policy, which default to the
            // bucket split at its first slash (`db/rp`), or on v3, which has no retention
            // policies, to the whole bucket. `epoch` returns integer timestamps, not RFC3339.
            // https://github.com/ipsocode/hypervel-influxdb/blob/main/docs/influxql.md#configuring-influxql
            'influxql' => [
                'database' => env('INFLUXDB_DATABASE'),
                'retentionPolicy' => env('INFLUXDB_RETENTION_POLICY'),
                'epoch' => env('INFLUXDB_EPOCH'),
            ],

            // INFLUXDB_BATCHING=true holds points in worker memory and sends them in batches,
            // sized for the server version unless set here; `maxBuffered`, `overflow`, `onFailure`
            // and the InfluxDB client's write options, such as `maxRetries`, can be added too.
            // https://github.com/ipsocode/hypervel-influxdb/blob/main/docs/writing.md#batching-writes
            'write' => [
                'writeType' => env('INFLUXDB_BATCHING', false) ? WriteType::BATCHING : WriteType::SYNCHRONOUS,
                'batchSize' => env('INFLUXDB_BATCH_SIZE'),
                'batchSizeMb' => env('INFLUXDB_BATCH_SIZE_MB'),
                'flushInterval' => env('INFLUXDB_FLUSH_INTERVAL'),
            ],

            // Any other option InfluxDB2\Client accepts (timeout, proxy, tags, ...) can be added here.
            // https://github.com/ipsocode/hypervel-influxdb/blob/main/docs/configuration.md#passing-options-to-the-client
        ],
    ],
];
