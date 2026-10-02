<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Concerns;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Ipsocode\InfluxDB\Write\Availability;
use Ipsocode\InfluxDB\Write\BatchingWriter;
use Ipsocode\InfluxDB\Write\BatchOptions;
use Ipsocode\InfluxDB\Write\Failover;
use Ipsocode\InfluxDB\Write\FailoverOptions;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * Builds BatchingWriters whose HTTP layer is a Guzzle mock, and reads back the batches they sent.
 *
 * MockHandler sleeps the transport's delay with usleep(), which Swoole's hooks turn into a yield,
 * so a delayed send suspends its coroutine as a request to a real server does.
 */
trait MocksWrites
{
    /**
     * The requests sent through the mocked transport, oldest first.
     *
     * @var list<array{request: RequestInterface, response: mixed}>
     */
    protected array $history = [];

    /**
     * When each request was sent, in hrtime() nanoseconds, oldest first.
     *
     * @var list<int>
     */
    protected array $sentAt = [];

    /**
     * A Guzzle client that answers with the given responses, in order, recording every request.
     *
     * Without a delay the option is left out: MockHandler sleeps any delay
     * it is given, even 0, and a hooked usleep(0) still yields.
     *
     * @param list<Response|Throwable> $responses
     * @param int $delay the milliseconds each response takes
     */
    protected function transport(array $responses, int $delay = 0): Guzzle
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $stack->push(Middleware::tap(function (): void {
            $this->sentAt[] = hrtime(true);
        }));

        return new Guzzle(['handler' => $stack] + ($delay > 0 ? ['delay' => $delay] : []));
    }

    /**
     * A writer for the `main` connection whose transport answers with the given responses.
     *
     * The batching limits default to ones no test reaches by accident. The client's retries are off,
     * so a failure is final at once, and its log goes to /dev/null rather than the test's output.
     *
     * @param array<string, mixed> $batching BatchOptions constructor arguments, by name
     * @param list<Response|Throwable> $responses
     * @param int $delay the milliseconds each response takes
     * @param array<string, mixed> $writeOptions the client's write options
     * @param array<string, mixed> $options client options, over the fixture's
     * @param null|Failover $failover the connections a batch the `main` connection cannot take falls back to
     */
    protected function writer(array $batching = [], array $responses = [], int $delay = 0, array $writeOptions = [], array $options = [], ?Failover $failover = null): BatchingWriter
    {
        return new BatchingWriter(
            $options + self::clientOptions('main', $this->transport($responses, $delay)),
            'main',
            new BatchOptions(...$batching + [
                'batchSize' => 100,
                'batchBytes' => 1_000_000,
                'flushInterval' => 60.0,
                'maxBuffered' => 1_000,
            ]),
            $writeOptions + ['maxRetries' => 0],
            failover: $failover,
        );
    }

    /**
     * A failover of the `main` connection's writes to the given fallbacks, each over a transport answering with its responses.
     *
     * The fallbacks' clients are named after them: `backup` writes to `http://backup.localhost:8086`,
     * bucket `backup-bucket` and org `backup-org`.
     *
     * @param array<string, list<Response|Throwable>> $fallbacks the responses of each fallback, by connection name, in the order to try them
     * @param float $cooldown the seconds a connection that failed is skipped
     * @param null|Availability $availability the cooldowns to share, or new ones
     */
    protected function failover(array $fallbacks, float $cooldown = 30.0, ?Availability $availability = null): Failover
    {
        $options = [];

        foreach ($fallbacks as $name => $responses) {
            $options[$name] = self::clientOptions($name, $this->transport($responses));
        }

        return new Failover(
            'main',
            self::clientOptions('main', null),
            new FailoverOptions(array_keys($options), $cooldown),
            $availability ?? new Availability,
            $options,
        );
    }

    /**
     * The client options of a connection named after itself, such as `http://backup.localhost:8086` and bucket `backup-bucket`.
     *
     * The `main` connection keeps the fixture's `http://localhost:8086`. The client's log goes to /dev/null rather than the test's output.
     *
     * @return array<string, mixed>
     */
    protected static function clientOptions(string $connection, ?Guzzle $httpClient): array
    {
        return [
            'url' => $connection === 'main' ? 'http://localhost:8086' : "http://{$connection}.localhost:8086",
            'token' => $connection . '-token',
            'bucket' => "{$connection}-bucket",
            'org' => "{$connection}-org",
            'precision' => 'ns',
            'logFile' => '/dev/null',
        ] + ($httpClient === null ? [] : ['httpClient' => $httpClient]);
    }

    /**
     * Responses accepting the given number of writes, as InfluxDB does: 204 No Content.
     *
     * @return list<Response>
     */
    protected static function accepted(int $writes = 1): array
    {
        return array_fill(0, $writes, new Response(204));
    }

    /**
     * The lines of every request sent, oldest first, one list per request.
     *
     * @return list<list<string>>
     */
    protected function sentBatches(): array
    {
        return array_map(
            static fn (array $entry): array => explode("\n", (string) $entry['request']->getBody()),
            $this->history,
        );
    }

    /**
     * The path and query string of every request sent, oldest first, such as `/api/v2/write?org=o&bucket=b&precision=ns`.
     *
     * @return list<string>
     */
    protected function sentTo(): array
    {
        return array_map(
            static fn (array $entry): string => $entry['request']->getUri()->getPath() . '?' . $entry['request']->getUri()->getQuery(),
            $this->history,
        );
    }

    /**
     * The host, path and query string of every request sent, oldest first, such as `backup.localhost /api/v2/write?org=o&bucket=b&precision=ns`.
     *
     * @return list<string>
     */
    protected function sentThrough(): array
    {
        return array_map(
            static fn (array $entry): string => $entry['request']->getUri()->getHost() . ' ' . $entry['request']->getUri()->getPath() . '?' . $entry['request']->getUri()->getQuery(),
            $this->history,
        );
    }
}
