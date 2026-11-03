<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Support;

use Http\Client\Common\Plugin;
use Http\Promise\Promise;
use Hypervel\Database\ConnectionResolver;
use Psr\Http\Message\RequestInterface;

/**
 * Returns the request's idle database sessions before each request to InfluxDB.
 *
 * Hypervel does this for its own HTTP client; a custom client has to do it itself, or a request
 * that has queried the database holds its pooled session while InfluxDB answers and while the
 * client's write retries wait. A session in a transaction or pinned is kept.
 */
final class ReleasesIdleDatabaseConnections implements Plugin
{
    public function handleRequest(RequestInterface $request, callable $next, callable $first): Promise
    {
        ConnectionResolver::releaseIdleConnections();

        return $next($request);
    }
}
