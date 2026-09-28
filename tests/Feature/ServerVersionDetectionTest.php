<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Hypervel\Context\CoroutineContext;
use Hypervel\Core\Events\BeforeServerFork;
use Ipsocode\InfluxDB\InfluxDBManager;
use Ipsocode\InfluxDB\Tests\Concerns\MocksInfluxQL;
use Ipsocode\InfluxDB\Tests\TestCase;
use ReflectionClass;
use Throwable;

use function Hypervel\Coroutine\run;

/**
 * The servers' versions, asked for once when the server starts rather than by every worker.
 *
 * InfluxDBManager::detectServerVersions() runs in the master process,
 * outside any coroutine, just before the server forks its workers. These
 * tests run outside a coroutine too, so the versions land in the
 * non-coroutine context as they do there. A worker is a fork of that
 * process, and its requests run in coroutines that read the context the
 * master left, as the coroutine run() starts here does.
 */
class ServerVersionDetectionTest extends TestCase
{
    use MocksInfluxQL;

    protected bool $runTestsInCoroutine = false;

    public function testEachConnectionsServerIsAskedOnceOverAConnectionThatIsNotKeptOpen(): void
    {
        $this->transport('main', [self::pingResponse('v2.7.12')]);
        $this->transport('analytics', [self::pingResponse('1.8.10')]);

        $this->app->get(InfluxDBManager::class)->detectServerVersions();

        $this->assertSame(['GET localhost /ping', 'GET analytics.localhost /ping'], $this->requests());
        $this->assertSame(['close', 'close'], array_map(
            static fn (array $entry): string => $entry['request']->getHeaderLine('Connection'),
            $this->history,
        ));
        $this->assertSame(['main' => 'v2.7.12', 'analytics' => '1.8.10'], CoroutineContext::getFromNonCoroutine('__influxdb.server_versions'));
    }

    public function testAWorkersRequestsUseTheVersionsWithoutAskingAgain(): void
    {
        $this->app->get('config')->set('influxdb.connections.main.version', 'v2');
        $this->transport('main', [self::pingResponse('v2.7.12'), self::emptyResponse()]);
        $this->transport('analytics', [self::pingResponse('1.8.10')]);

        $manager = $this->app->get(InfluxDBManager::class);
        $manager->detectServerVersions();

        $known = null;
        $rows = null;
        $error = null;

        // An exception left inside a coroutine ends the process, so it is carried out.
        run(function () use ($manager, &$known, &$rows, &$error): void {
            try {
                $known = $manager->influxql('main')->getServerVersion();
                $rows = $manager->table('cpu')->get()->all();
            } catch (Throwable $exception) {
                $error = $exception;
            }
        });

        if ($error !== null) {
            throw $error;
        }

        $this->assertSame('v2.7.12', $known);
        $this->assertSame([], $rows);
        $this->assertSame(['GET localhost /ping', 'GET analytics.localhost /ping', 'POST localhost /query'], $this->requests());
    }

    public function testTheVersionEachServerNamedIsReadBackByConnectionName(): void
    {
        $this->transport('main', [self::pingResponse('v2.7.12')]);
        $this->transport('analytics', [self::pingResponse(null)]);

        $manager = $this->app->get(InfluxDBManager::class);

        $this->assertNull($manager->getDetectedServerVersion('main'), 'Nothing is known before the servers are asked.');

        $manager->detectServerVersions();

        $inWorker = null;

        run(function () use ($manager, &$inWorker): void {
            $inWorker = $manager->getDetectedServerVersion('main');
        });

        $this->assertSame('v2.7.12', $manager->getDetectedServerVersion('main'));
        $this->assertSame('v2.7.12', $inWorker, 'A worker\'s coroutine reads what the master process detected.');
        $this->assertNull($manager->getDetectedServerVersion('analytics'), 'Its server named no version.');
        $this->assertNull($manager->getDetectedServerVersion('missing'), 'No connection has that name.');
        $this->assertSame(['GET localhost /ping', 'GET analytics.localhost /ping'], $this->requests(), 'Reading a version asks no server.');
    }

    public function testAServerThatCannotTellIsAskedByTheWorkerInstead(): void
    {
        $this->app->get('config')->set('influxdb.connections.silent', [
            'url' => 'http://silent.localhost:8086',
            'token' => 'silent-token',
            'bucket' => 'silent-bucket',
            'org' => 'silent-org',
        ]);
        $this->transport('main', [new Response(503, [], 'unavailable'), self::pingResponse('1.8.10'), self::emptyResponse()]);
        $this->transport('analytics', [new ConnectException('Connection refused', new Request('GET', '/ping'))]);
        $this->transport('silent', [self::pingResponse(null)]);

        $manager = $this->app->get(InfluxDBManager::class);
        $manager->detectServerVersions();

        $this->assertSame([], CoroutineContext::getFromNonCoroutine('__influxdb.server_versions'));
        $this->assertFalse($manager->influxql('main')->knowsServerVersion());
        $this->assertSame([], $manager->table('cpu')->get()->all());
        $this->assertSame([
            'GET localhost /ping',
            'GET analytics.localhost /ping',
            'GET silent.localhost /ping',
            'GET localhost /ping',
            'POST localhost /query',
        ], $this->requests());
    }

    public function testAConnectionThatIsNotFullyConfiguredIsLeftOut(): void
    {
        $this->app->get('config')->set('influxdb.connections.incomplete', ['url' => 'http://incomplete.localhost:8086']);
        $this->transport('main', [self::pingResponse('v2.7.12')]);
        $this->transport('analytics', [self::pingResponse('1.8.10')]);

        $this->app->get(InfluxDBManager::class)->detectServerVersions();

        $this->assertSame(['main' => 'v2.7.12', 'analytics' => '1.8.10'], CoroutineContext::getFromNonCoroutine('__influxdb.server_versions'));
        $this->assertSame(['GET localhost /ping', 'GET analytics.localhost /ping'], $this->requests());
    }

    public function testTheProviderAsksBeforeTheServerForksItsWorkers(): void
    {
        $this->transport('main', [self::pingResponse('v2.7.12')]);
        $this->transport('analytics', [self::pingResponse('1.8.10')]);

        // Hypervel dispatches it from Server::start(), with the Swoole server
        // the listeners here have no use for.
        $this->app->get('events')->dispatch((new ReflectionClass(BeforeServerFork::class))->newInstanceWithoutConstructor());

        $this->assertSame(['main' => 'v2.7.12', 'analytics' => '1.8.10'], CoroutineContext::getFromNonCoroutine('__influxdb.server_versions'));
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
