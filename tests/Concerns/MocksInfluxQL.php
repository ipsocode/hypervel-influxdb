<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Concerns;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use InfluxDB2\Client;
use Ipsocode\InfluxDB\InfluxQL\Connection;
use Psr\Http\Message\RequestInterface;

/**
 * Builds InfluxQL connections whose HTTP layer is a Guzzle mock.
 *
 * The upstream client accepts any PSR-18 client through its `httpClient`
 * option, which is also how a consuming application binds a coroutine-safe
 * one. Handing it a Guzzle client on a MockHandler keeps every statement the
 * suite runs off the network and records each request for inspection.
 */
trait MocksInfluxQL
{
    /**
     * The requests sent through the mocked transport, oldest first.
     *
     * @var list<array{request: RequestInterface, response: null|Response}>
     */
    protected array $history = [];

    /**
     * The version the mocked server names on /ping, or null to answer no /ping.
     *
     * The Builder reads the server's version before its first statement on a
     * connection, so a test that runs the builder sets this; statements run
     * directly on the connection send no /ping.
     */
    protected ?string $pingVersion = null;

    /**
     * Build a connection whose transport answers with the given responses, in order.
     *
     * The connection is made as the manager makes it, so the config's
     * `version` picks its class: `config: ['version' => 'v2']` for 2.x. When
     * `$pingVersion` is set, the builder's /ping is answered first.
     *
     * @param list<Response> $responses
     * @param array<string, mixed> $config the connection config, on top of the fixture's
     * @param array<string, mixed> $options extra upstream client options
     */
    protected function connection(array $responses = [], array $config = [], array $options = []): Connection
    {
        $config += ['name' => 'main', 'bucket' => 'main-bucket'];

        if ($this->pingVersion !== null) {
            array_unshift($responses, self::pingResponse($this->pingVersion));
        }

        return Connection::make($this->client($responses, $options + ['bucket' => $config['bucket']]), $config);
    }

    /**
     * Build an upstream client whose transport answers with the given responses, in order.
     *
     * @param list<Response> $responses
     * @param array<string, mixed> $options
     */
    protected function client(array $responses = [], array $options = []): Client
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new Client($options + [
            'url' => 'http://localhost:8086',
            'token' => 'main-token',
            'bucket' => 'main-bucket',
            'org' => 'main-org',
            'httpClient' => new Guzzle(['handler' => $stack]),
        ]);
    }

    /**
     * A 200 response carrying the given decoded `results` list.
     *
     * @param list<array<string, mixed>> $results
     */
    protected static function response(array $results): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode(['results' => $results], JSON_THROW_ON_ERROR));
    }

    /**
     * A 200 response for one statement that returned the given series.
     *
     * @param list<array<string, mixed>> $series
     */
    protected static function seriesResponse(array $series): Response
    {
        return self::response([['statement_id' => 0, 'series' => $series]]);
    }

    /**
     * A 200 response for one statement that returned no points.
     */
    protected static function emptyResponse(): Response
    {
        return self::response([['statement_id' => 0]]);
    }

    /**
     * A 200 response with no `results` list, as InfluxDB 2.x answers a DELETE that succeeds.
     */
    protected static function noResultsResponse(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], '{}');
    }

    /**
     * A 204 answer to /ping, naming the given version the way InfluxDB does, or none.
     */
    protected static function pingResponse(?string $version = '1.8.10'): Response
    {
        return new Response(204, $version === null ? [] : ['X-Influxdb-Version' => $version]);
    }

    /**
     * A 200 response for one statement the server refused.
     */
    protected static function errorResponse(string $error): Response
    {
        return self::response([['statement_id' => 0, 'error' => $error]]);
    }

    /**
     * One series of a response.
     *
     * @param array<string, string> $tags
     * @param list<string> $columns
     * @param list<list<mixed>> $values
     * @return array<string, mixed>
     */
    protected static function series(string $name, array $columns, array $values, array $tags = []): array
    {
        $series = ['name' => $name, 'columns' => $columns, 'values' => $values];

        if ($tags !== []) {
            $series['tags'] = $tags;
        }

        return $series;
    }

    /**
     * The most recent request sent through the mocked transport.
     */
    protected function lastRequest(): RequestInterface
    {
        $this->assertNotEmpty($this->history, 'No request was sent.');

        return $this->history[array_key_last($this->history)]['request'];
    }

    /**
     * The InfluxQL statement the most recent request carried.
     */
    protected function lastStatement(): string
    {
        return $this->statementOf($this->lastRequest());
    }

    /**
     * The InfluxQL statement of every /query request sent, oldest first.
     *
     * @return list<string>
     */
    protected function statements(): array
    {
        return array_map(fn (array $entry): string => $this->statementOf($entry['request']), $this->queries());
    }

    /**
     * The /query requests sent through the mocked transport, oldest first: every request but a /ping.
     *
     * @return list<array{request: RequestInterface, response: null|Response}>
     */
    protected function queries(): array
    {
        return array_values(array_filter(
            $this->history,
            static fn (array $entry): bool => $entry['request']->getUri()->getPath() === '/query',
        ));
    }

    /**
     * The method and path of every request sent, oldest first, such as `GET /ping`.
     *
     * @return list<string>
     */
    protected function requestLines(): array
    {
        return array_map(
            static fn (array $entry): string => $entry['request']->getMethod() . ' ' . $entry['request']->getUri()->getPath(),
            $this->history,
        );
    }

    /**
     * The InfluxQL statement a request carried.
     */
    protected function statementOf(RequestInterface $request): string
    {
        parse_str((string) $request->getBody(), $form);

        $this->assertIsString($form['q'] ?? null, 'The request carried no statement.');

        return $form['q'];
    }

    /**
     * The query-string parameters of the most recent request.
     *
     * @return array<string, string>
     */
    protected function lastQueryParameters(): array
    {
        parse_str($this->lastRequest()->getUri()->getQuery(), $parameters);

        return $parameters;
    }
}
