<?php

declare(strict_types=1);

use InfluxDB2\WriteType;

return [
    /*
    |--------------------------------------------------------------------------
    | Default Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the connections below you wish to use as
    | your default connection for all work. Of course, you may use many
    | connections at once using the manager class.
    |
    */

    'default' => env('INFLUXDB_CONNECTION', 'main'),

    /*
    |--------------------------------------------------------------------------
    | InfluxDB Connections
    |--------------------------------------------------------------------------
    |
    | Here are each of the connections setup for your application.
    |
    */

    'connections' => [
        'main' => [
            // The major version of InfluxDB the server runs: v1, v2 or v3.
            // Writes use the 2.x API either way, which 1.8+ and 3 serve for
            // compatibility; the version decides how InfluxQL queries compile,
            // through InfluxDB::table() or the `influxql` database driver, and
            // both check it against the one the server reports. SQL, through
            // the `influxdb` database driver, needs v3.
            'version' => env('INFLUXDB_VERSION', 'v1'),
            'url' => env('INFLUXDB_URL'),
            'token' => env('INFLUXDB_TOKEN'),
            'bucket' => env('INFLUXDB_BUCKET'),
            'org' => env('INFLUXDB_ORG'),
            'verifySSL' => env('INFLUXDB_VERIFY_SSL', true),
            'precision' => env('INFLUXDB_PRECISION', 'ns'),
            'debug' => env('INFLUXDB_DEBUG', false),

            // InfluxQL runs over the /query endpoint InfluxDB 1.x defines, which
            // addresses data by database and retention policy rather than by
            // bucket. Both default to what the bucket's name says:
            // - v1: the bucket split at its first slash, `db/rp`, or `db` on
            //   the database's default policy, as 1.8+ splits the bucket its
            //   2.x compatibility API writes;
            // - v2: split the same way, as 2.4+ derives a bucket's virtual
            //   DBRP mapping. A bucket named otherwise needs an explicit
            //   mapping on the server, and its database named here;
            // - v3: the whole bucket, slash included, which is the database
            //   3 stores the 2.x API's points in. 3 has no retention
            //   policies, so leave retentionPolicy unset.
            // `epoch` returns timestamps as integers of that precision (ns, u,
            // ms, s, m, h, and on v3 d and w) instead of RFC3339 strings.
            'influxql' => [
                'database' => env('INFLUXDB_DATABASE'),
                'retentionPolicy' => env('INFLUXDB_RETENTION_POLICY'),
                'epoch' => env('INFLUXDB_EPOCH'),
            ],

            // How InfluxDBManager::writeApi() writes. By default each write() is
            // one request. With INFLUXDB_BATCHING=true the worker buffers points
            // and sends them in batches instead; read the README's "Batching
            // writes" first, since a batch lives in one worker's memory until it
            // is sent. A batch is sent once it holds `batchSize` lines of line
            // protocol or `batchSizeMb` megabytes, whichever comes first, and at
            // the latest `flushInterval` seconds after its first point. Left
            // null, they follow InfluxData's advice for the version:
            // - v1: 5,000 lines or 25 MB, the most 1.x takes in a request;
            // - v2: 5,000 lines or 50 MB, the most InfluxDB Cloud takes;
            // - v3: 10,000 lines or 10 MB, whichever is met first;
            // and 1 second. `maxBuffered`, `overflow` and `onFailure`, and the
            // upstream WriteApi's own options, such as `maxRetries`, can be
            // added here too.
            'write' => [
                'writeType' => env('INFLUXDB_BATCHING', false) ? WriteType::BATCHING : WriteType::SYNCHRONOUS,
                'batchSize' => env('INFLUXDB_BATCH_SIZE'),
                'batchSizeMb' => env('INFLUXDB_BATCH_SIZE_MB'),
                'flushInterval' => env('INFLUXDB_FLUSH_INTERVAL'),
            ],

            // Everything but `version` and `influxql` is passed to
            // InfluxDB2\Client, so any other option it accepts (timeout, proxy,
            // allow_redirects, tags, logFile, httpClient, ...) can be added
            // here.
        ],
    ],
];
