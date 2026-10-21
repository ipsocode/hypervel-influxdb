<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Write\Concerns;

use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Swoole\Coroutine\CanceledException;
use Throwable;

/**
 * Reports a failure the writer does not throw, and runs what may fail without throwing.
 *
 * @see docs/internals.md#failures
 */
trait ReportsFailures
{
    /**
     * Report an exception through the application's exception handler, or, failing that, to the error log.
     */
    protected function report(Throwable $exception): void
    {
        if (self::attempt(fn () => Container::getInstance()->make(ExceptionHandler::class)->report($exception)) !== null) {
            error_log((string) $exception);
        }
    }

    /**
     * Run a callback, and return what it throws instead of throwing it.
     *
     * A coroutine's cancellation is thrown on, as Hypervel expects, rather
     * than taken for a failure.
     */
    protected static function attempt(callable $callback): ?Throwable
    {
        try {
            $callback();
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return $exception;
        }

        return null;
    }
}
