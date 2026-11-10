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
    | https://github.com/ipsocode/hypervel-influxdb/blob/main/src/docs/configuration.md#connections
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
            // env() converts only the words true and false, so `0` and `off` are read here; anything else verifies.
            'verifySSL' => filter_var(env('INFLUXDB_VERIFY_SSL', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,
            'precision' => env('INFLUXDB_PRECISION', 'ns'),
            'debug' => filter_var(env('INFLUXDB_DEBUG', false), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,

            // InfluxQL addresses a database and retention policy, which default to the
            // bucket split at its first slash (`db/rp`), or on v3, which has no retention
            // policies, to the whole bucket. `epoch` returns integer timestamps, not RFC3339.
            // https://github.com/ipsocode/hypervel-influxdb/blob/main/src/docs/influxql.md#configuring-influxql
            'influxql' => [
                'database' => env('INFLUXDB_DATABASE'),
                'retentionPolicy' => env('INFLUXDB_RETENTION_POLICY'),
                'epoch' => env('INFLUXDB_EPOCH'),
            ],

            // INFLUXDB_BATCHING=true holds points in worker memory and sends them in batches,
            // sized for the server version unless set here; `maxBuffered`, `overflow`, `onFailure`
            // ('log' by default: a batch that cannot be written is logged and dropped), `maxAttempts`
            // (the sends a replayed batch gets before it is dropped) and the
            // InfluxDB client's write options, such as `maxRetries`, can be added too.
            // https://github.com/ipsocode/hypervel-influxdb/blob/main/src/docs/writing.md#batching-writes
            //
            // INFLUXDB_FALLBACK names the connections, comma-separated, a write this connection cannot
            // take falls back to, in order; a connection that fails is skipped for `cooldown` seconds.
            // https://github.com/ipsocode/hypervel-influxdb/blob/main/src/docs/writing.md#falling-back-to-other-connections
            // The numbers are read as numbers; a value that is not numeric is left for the write options to refuse.
            'write' => [
                'writeType' => (filter_var(env('INFLUXDB_BATCHING', false), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false) ? WriteType::BATCHING : WriteType::SYNCHRONOUS,
                'batchSize' => is_numeric(env('INFLUXDB_BATCH_SIZE')) ? env('INFLUXDB_BATCH_SIZE') + 0 : env('INFLUXDB_BATCH_SIZE'),
                'batchSizeMb' => is_numeric(env('INFLUXDB_BATCH_SIZE_MB')) ? env('INFLUXDB_BATCH_SIZE_MB') + 0 : env('INFLUXDB_BATCH_SIZE_MB'),
                'flushInterval' => is_numeric(env('INFLUXDB_FLUSH_INTERVAL')) ? env('INFLUXDB_FLUSH_INTERVAL') + 0 : env('INFLUXDB_FLUSH_INTERVAL'),
                'fallback' => env('INFLUXDB_FALLBACK'),
                'cooldown' => is_numeric(env('INFLUXDB_COOLDOWN')) ? env('INFLUXDB_COOLDOWN') + 0 : env('INFLUXDB_COOLDOWN'),
            ],

            // Any other option InfluxDB2\Client accepts (timeout, proxy, tags, ...) can be added here.
            // https://github.com/ipsocode/hypervel-influxdb/blob/main/src/docs/configuration.md#passing-options-to-the-client
        ],
    ],
];
