<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Support;

use Exception;
use Http\Client\Common\Plugin;
use Http\Client\Exception\NetworkException;
use Http\Promise\Promise;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Hands the client's write retries the network failures of a PSR-18 client.
 *
 * InfluxDB2\WriteRetry retries an error without a retryable status only when its previous
 * exception is php-http's NetworkException. Guzzle throws its own ConnectException, which
 * implements PSR-18's NetworkExceptionInterface instead, so a refused connection, a DNS failure,
 * a reset or a timeout was never retried.
 */
class RetryableNetworkErrors implements Plugin
{
    public function handleRequest(RequestInterface $request, callable $next, callable $first): Promise
    {
        try {
            return $next($request);
        } catch (Exception $e) {
            // php-http's emulation of an async client turns only php-http's own exceptions into a rejected promise,
            // so Guzzle's ConnectException is thrown out of $next() rather than rejected.
            if (! $e instanceof NetworkExceptionInterface) {
                throw $e;
            }

            throw new NetworkException($e->getMessage(), $request, $e);
        }
    }
}
