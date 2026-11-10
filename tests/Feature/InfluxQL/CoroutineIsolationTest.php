<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature\InfluxQL;

use GuzzleHttp\Psr7\Response;
use Ipsocode\InfluxDB\Tests\Concerns\MocksInfluxQL;
use Ipsocode\InfluxDB\Tests\TestCase;
use Psr\Http\Message\RequestInterface;

use function Hypervel\Coroutine\parallel;

/**
 * Coroutines sharing a worker's InfluxQL connection, as the manager keeps one, each get their own statement and rows.
 */
class CoroutineIsolationTest extends TestCase
{
    use MocksInfluxQL;

    public function testConcurrentQueriesOnTheSharedConnectionKeepTheirOwnBindingsAndResults(): void
    {
        // The server answers each request from the host in its own statement, after a pause that lets the other coroutine run.
        $answer = function (RequestInterface $request): Response {
            usleep(5_000);

            $host = str_contains($this->statementOf($request), "'web1'") ? 'web1' : 'web2';

            return self::seriesResponse([self::series('cpu', ['time', 'host', 'usage_user'], [['2024-01-01T00:00:00Z', $host, $host === 'web1' ? 1 : 2]])]);
        };

        $connection = $this->connection([$answer, $answer, $answer, $answer])->setServerVersion('1.8.10');

        $results = parallel([
            static function () use ($connection): array {
                $query = $connection->table('cpu')->where('host', 'web1');
                usleep(1_000);

                return [$query->toRawSql(), $query->first()?->host, $query->value('usage_user')];
            },
            static function () use ($connection): array {
                $query = $connection->table('cpu')->where('host', 'web2');
                usleep(1_000);

                return [$query->toRawSql(), $query->first()?->host, $query->value('usage_user')];
            },
        ]);

        $this->assertSame([
            ['select * from "cpu" where "host" = \'web1\'', 'web1', 1],
            ['select * from "cpu" where "host" = \'web2\'', 'web2', 2],
        ], $results);
        $this->assertCount(4, $this->history);
    }
}
