<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Support;

use Hypervel\Database\LostConnectionException;
use InfluxDB2\ApiException;
use Psr\Http\Client\NetworkExceptionInterface;
use Throwable;

/**
 * Let Hypervel's lost-connection detection see a failed HTTP call.
 *
 * The detector inspects PDOException, ErrorException and LostConnectionException.
 * This package's drivers fail with the client's ApiException, so one the transport
 * caused (a network failure, or no HTTP status at all) is passed on as a
 * LostConnectionException with the same message, and the detector's own list of
 * messages ("reset by peer", ...) decides whether to retry once. An ApiException
 * with an HTTP status is the server's answer: its message quotes the statement's
 * values, so it is never matched against that list.
 */
trait DetectsLostTransport
{
    /**
     * Determine if the given exception was caused by a lost connection.
     */
    protected function causedByLostConnection(Throwable $e): bool
    {
        return parent::causedByLostConnection(
            $e instanceof ApiException && $this->causedByTransport($e)
                ? new LostConnectionException($e->getMessage(), 0, $e)
                : $e
        );
    }

    /**
     * Determine if the transport, not the server's answer, caused the exception.
     */
    private function causedByTransport(ApiException $e): bool
    {
        return $e->getPrevious() instanceof NetworkExceptionInterface || ! $e->getCode();
    }
}
