<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Concerns;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Ipsocode\InfluxDB\Write\BatchingWriter;
use Ipsocode\InfluxDB\Write\BatchOptions;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * Builds BatchingWriters whose HTTP layer is a Guzzle mock, and reads back the batches they sent.
 *
 * The mock answers each request with the next queued response, after the
 * transport's delay. MockHandler sleeps that delay with usleep(), which
 * Swoole's hooks turn into a yield inside a coroutine, so a delayed send
 * suspends the coroutine that makes it, as a request to a real server does.
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
     * The batching options default to limits no test reaches by accident: 100
     * lines, a megabyte, a minute and 1,000 points. The client's retries are
     * off, so a failure is final at once, and the lines the client logs about
     * them go nowhere rather than to the test's output.
     *
     * @param array<string, mixed> $batching BatchOptions constructor arguments, by name
     * @param list<Response|Throwable> $responses
     * @param int $delay the milliseconds each response takes
     * @param array<string, mixed> $writeOptions upstream's write options
     * @param array<string, mixed> $options client options, over the fixture's
     */
    protected function writer(array $batching = [], array $responses = [], int $delay = 0, array $writeOptions = [], array $options = []): BatchingWriter
    {
        return new BatchingWriter(
            $options + [
                'url' => 'http://localhost:8086',
                'token' => 'main-token',
                'bucket' => 'main-bucket',
                'org' => 'main-org',
                'precision' => 'ns',
                'logFile' => '/dev/null',
                'httpClient' => $this->transport($responses, $delay),
            ],
            'main',
            new BatchOptions(...$batching + [
                'batchSize' => 100,
                'batchBytes' => 1_000_000,
                'flushInterval' => 60.0,
                'maxBuffered' => 1_000,
            ]),
            $writeOptions + ['maxRetries' => 0],
        );
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
}
