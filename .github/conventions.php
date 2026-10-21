<?php

declare(strict_types=1);

/*
 * This package's settings for .github/scripts/conventions.php, the conventions check every
 * ipsocode/hypervel-* package imports. initial.yml runs it ahead of the test jobs, and
 * `composer conventions` locally. The script's header lists the rules.
 */

return [
    // The name this package writes under wherever the application writes too:
    // __influxdb.* context keys, influxdb:* commands and store keys, influxdb-*
    // publish tags, INFLUXDB_* env vars and config/influxdb.php.
    'slug' => 'influxdb',

    // Paths under the package root that no rule governs.
    'excluded' => [],

    // Namespaces banned on top of Illuminate\ and Laravel\, each with what to use
    // instead.
    'banned_namespaces' => [],

    // Function-name prefixes banned in shipped code, each with what to use instead.
    'banned_functions' => [],

    // Paths phpunit.xml's <source> may leave out of the coverage gate.
    'coverage_excludes' => [],

    // The exceptions, per rule: '<path>' => [<exact number of hits>, '<why>']. The
    // check fails as soon as a count stops matching, either way. This package is an
    // InfluxQL query builder and driver, so the raw-sql rule meets the raw API it
    // implements; each entry says why its calls are safe.
    'allowed' => [
        'raw-sql' => [
            'src/InfluxQL/Builder.php' => [5, "the builder's own raw API: subqueries and where clauses carry their bindings, groupByTime() embeds only a duration literal checked against Dialect::DURATION, delete() runs the grammar's compiled statement with its bindings, and raw() hands the caller an Expression"],
            'src/InfluxQL/Driver/Builder.php' => [4, "the driver builder's own raw API: subqueries and where clauses carry their bindings, groupByTime() embeds only a duration literal checked against Dialect::DURATION, and rawValue() selects with its bindings"],
            'src/InfluxQL/Driver/Connection.php' => [3, "the driver connection's statement API, which runs InfluxQL through runInfluxQL() with its bindings"],
            'src/Write/BatchingWriter.php' => [1, "writeRaw() sends line protocol through InfluxDB's write API; it runs no query"],
            'src/Write/Failover.php' => [1, "writeRaw() sends line protocol a connection could not take through a fallback connection's write API; it runs no query"],
            'src/Write/FailoverWriter.php' => [1, "writeRaw() sends line protocol through InfluxDB's write API, as BatchingWriter's does; it runs no query"],
        ],
    ],
];
