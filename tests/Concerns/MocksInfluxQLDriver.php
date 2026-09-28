<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Concerns;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * Configures `influxql` database connections on InfluxDB connections whose transport is a Guzzle mock.
 *
 * The InfluxDB connections `v1`, `v2` and `v3` each run that version, on a
 * host of their own, such as `v1.localhost`, and share one transport. The
 * database connections `influxql-v1`, `influxql-v2` and `influxql-v3` run on
 * them, and `influxql` on `v1`. Their bucket is `telegraf/autogen`, which v1
 * and v2 address as database `telegraf` on retention policy `autogen`, and v3
 * as database `telegraf/autogen`.
 *
 * Every request is recorded, as MocksInfluxQL records them, and its
 * response helpers and readers apply. The /query requests are answered with
 * the responses queued on $responses, in order. A /ping is answered by the
 * transport itself, from $pings, so the driver's version check, which asks
 * before each pooled connection's first statement, needs nothing queued; a
 * test of the check changes the host's entry first.
 */
trait MocksInfluxQLDriver
{
    use MocksInfluxQL;

    /**
     * The mocked transport's answers to /query requests, in order.
     */
    protected MockHandler $responses;

    /**
     * What each host answers to /ping: the version it names, null to name none, or a response or failure of its own.
     *
     * @var array<string, null|Response|string|Throwable>
     */
    protected array $pings = [
        'v1.localhost' => '1.8.10',
        'v2.localhost' => 'v2.7.12',
        'v3.localhost' => '3.11.5',
    ];

    /**
     * Configure the InfluxDB connections on the mocked transport, and the database connections on them.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $this->responses = new MockHandler;

        $stack = HandlerStack::create(function (RequestInterface $request, array $options): PromiseInterface {
            if ($request->getUri()->getPath() !== '/ping') {
                return ($this->responses)($request, $options);
            }

            $ping = $this->pings[$request->getUri()->getHost()] ?? null;

            return match (true) {
                $ping instanceof Throwable => Create::rejectionFor($ping),
                $ping instanceof Response => Create::promiseFor($ping),
                default => Create::promiseFor(self::pingResponse($ping)),
            };
        });
        $stack->push(Middleware::history($this->history));

        $httpClient = new Guzzle(['handler' => $stack]);

        $config = $app->get('config');

        foreach (['v1', 'v2', 'v3'] as $version) {
            $config->set("influxdb.connections.{$version}", [
                'version' => $version,
                'url' => "http://{$version}.localhost:8086",
                'token' => "{$version}-token",
                'bucket' => 'telegraf/autogen',
                'org' => "{$version}-org",
                'httpClient' => $httpClient,
            ]);

            $config->set("database.connections.influxql-{$version}", ['driver' => 'influxql', 'connection' => $version]);
        }

        $config->set('database.connections.influxql', ['driver' => 'influxql', 'connection' => 'v1']);
    }
}
