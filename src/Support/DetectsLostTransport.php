<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Support;

use Hypervel\Database\LostConnectionException;
use InfluxDB2\ApiException;
use Throwable;

/**
 * Let Hypervel's lost-connection detection see a failed HTTP call.
 *
 * The detector only inspects PDOException and LostConnectionException. This
 * package's drivers fail with the client's ApiException, so it is passed on as
 * a LostConnectionException with the same message, and the detector's own
 * list of messages ("reset by peer", ...) decides whether to retry once.
 */
trait DetectsLostTransport
{
    /**
     * Determine if the given exception was caused by a lost connection.
     */
    protected function causedByLostConnection(Throwable $e): bool
    {
        return parent::causedByLostConnection(
            $e instanceof ApiException ? new LostConnectionException($e->getMessage(), 0, $e) : $e
        );
    }
}
