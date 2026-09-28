<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature\InfluxQL\Driver;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Hypervel\Support\Facades\DB;
use Ipsocode\InfluxDB\InfluxDBManager;
use Ipsocode\InfluxDB\Tests\Concerns\MocksInfluxQL;
use Ipsocode\InfluxDB\Tests\TestCase;
use Throwable;

use function Hypervel\Coroutine\run;

/**
 * The server's version, which the master process asked for, reused by the `influxql` driver's connections.
 *
 * Like ServerVersionDetectionTest, this runs outside a coroutine, as the
 * master process does when it asks each server for its version before it
 * forks the workers. A worker's requests run in coroutines that read what
 * it left, as the coroutine run() starts here does.
 */
class ServerVersionHandoffTest extends TestCase
{
    use MocksInfluxQL;

    protected bool $runTestsInCoroutine = false;

    public function testAConnectionMadeInAWorkerSendsItsStatementsWithoutAskingForTheVersionAgain(): void
    {
        $this->transport('main', [self::pingResponse('1.8.10'), self::emptyResponse()]);
        $this->transport('analytics', [self::pingResponse('v2.7.12')]);
        $this->app->get('config')->set('database.connections.influxql', ['driver' => 'influxql', 'connection' => 'main']);

        $this->app->get(InfluxDBManager::class)->detectServerVersions();

        $known = null;
        $rows = null;
        $error = null;

        // An exception left inside a coroutine ends the process, so it is carried out.
        run(function () use (&$known, &$rows, &$error): void {
            try {
                $connection = DB::connection('influxql');
                $known = $connection->getServerVersion();
                $rows = $connection->table('cpu')->get()->all();
            } catch (Throwable $exception) {
                $error = $exception;
            }
        });

        if ($error !== null) {
            throw $error;
        }

        $this->assertSame('1.8.10', $known);
        $this->assertSame([], $rows);
        $this->assertSame(['GET localhost /ping', 'GET analytics.localhost /ping', 'POST localhost /query'], $this->requests());
    }

    /**
     * Give a configured connection a transport that answers with the given responses, in order.
     *
     * @param list<Response|Throwable> $responses
     */
    private function transport(string $connection, array $responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        $this->app->get('config')->set("influxdb.connections.{$connection}.httpClient", new Guzzle(['handler' => $stack]));
    }

    /**
     * The method, host and path of every request sent, oldest first, such as `GET localhost /ping`.
     *
     * @return list<string>
     */
    private function requests(): array
    {
        return array_map(
            static fn (array $entry): string => implode(' ', [
                $entry['request']->getMethod(),
                $entry['request']->getUri()->getHost(),
                $entry['request']->getUri()->getPath(),
            ]),
            $this->history,
        );
    }
}
