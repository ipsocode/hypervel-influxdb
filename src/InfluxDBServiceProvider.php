<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB;

use Hypervel\Console\Events\AfterExecute;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Core\Events\BeforeServerFork;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Database\DatabaseManager;
use Hypervel\Foundation\Events\Terminating;
use Hypervel\Support\ServiceProvider;
use InfluxDB2\Client;
use Ipsocode\InfluxDB\InfluxQL\Driver\Connection as InfluxQLDriverConnection;
use Ipsocode\InfluxDB\Sql\SqlConnection;

/**
 * Registers the InfluxDB connection manager, the default client and the
 * `influxdb` and `influxql` database drivers.
 *
 * @see docs/hypervel.md#the-connection-manager
 */
class InfluxDBServiceProvider extends ServiceProvider
{
    /**
     * Register the application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/influxdb.php', 'influxdb');

        $this->registerFactory();
        $this->registerManager();
        $this->registerConnection();
        $this->registerSqlDriver();
        $this->registerInfluxqlDriver();
    }

    /**
     * Get configuration arrays whose entries should be merged by name.
     *
     * Without this, an application publishing config/influxdb.php with only its own
     * connection would silently lose the package's 'main' default instead of adding to it.
     */
    protected function mergeableOptions(string $name): array
    {
        return ['connections'];
    }

    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/influxdb.php' => config_path('influxdb.php'),
        ], 'influxdb-config');

        $events = $this->app->make('events');

        // Ask each connection's server for its version once, in the master
        // process before it forks the workers, which all inherit the answers.
        $events->listen(BeforeServerFork::class, function (): void {
            $this->app->make(InfluxDBManager::class)->detectServerVersions();
        });

        // Send a console command's batches when it finishes and when the console application
        // terminates outside any coroutine. HTTP requests terminate inside one: the timer sends theirs.
        $events->listen(AfterExecute::class, function (): void {
            $this->app->make(InfluxDBManager::class)->flushAll();
        });

        $events->listen(Terminating::class, function (): void {
            if (! Coroutine::inCoroutine()) {
                $this->app->make(InfluxDBManager::class)->flushAll();
            }
        });
    }

    /**
     * Register the client factory.
     */
    protected function registerFactory(): void
    {
        $this->app->singleton('influxdb.factory', fn () => new InfluxDBFactory);

        $this->app->alias('influxdb.factory', InfluxDBFactory::class);
    }

    /**
     * Register the connection manager.
     */
    protected function registerManager(): void
    {
        $this->app->singleton('influxdb', function (Application $app) {
            return new InfluxDBManager(
                $app->make('config'),
                $app->make('influxdb.factory'),
            );
        });

        $this->app->alias('influxdb', InfluxDBManager::class);
    }

    /**
     * Register the default connection binding.
     *
     * Resolving InfluxDB2\Client from the container yields the default connection.
     */
    protected function registerConnection(): void
    {
        $this->app->singleton('influxdb.connection', function (Application $app) {
            return $app->make('influxdb')->connection();
        });

        $this->app->alias('influxdb.connection', Client::class);
    }

    /**
     * Register the `influxdb` database driver, which runs SQL on InfluxDB 3.
     *
     * SqlConnection::fromConfig() makes each `'driver' => 'influxdb'` database connection from
     * the InfluxDB connection it names; Hypervel's database manager and its pool keep them.
     */
    protected function registerSqlDriver(): void
    {
        $this->callAfterResolving('db', function (DatabaseManager $db): void {
            $db->extend('influxdb', fn (array $config): SqlConnection => SqlConnection::fromConfig(
                $this->app->make(InfluxDBManager::class),
                $config,
            ));
        });
    }

    /**
     * Register the `influxql` database driver, which runs InfluxQL on InfluxDB 1.x, 2.x and 3.
     *
     * InfluxQL\Driver\Connection::fromConfig() makes each `'driver' => 'influxql'` database connection
     * from the InfluxDB connection it names; Hypervel's database manager and its pool keep them.
     */
    protected function registerInfluxqlDriver(): void
    {
        $this->callAfterResolving('db', function (DatabaseManager $db): void {
            $db->extend('influxql', fn (array $config): InfluxQLDriverConnection => InfluxQLDriverConnection::fromConfig(
                $this->app->make(InfluxDBManager::class),
                $config,
            ));
        });
    }
}
