<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Console;

use Hypervel\Console\Command;
use InvalidArgumentException;
use Ipsocode\InfluxDB\InfluxDBManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Writes the batches a connection's failure store kept, and prints how many were written.
 *
 * @see docs/writing.md#replaying-failed-batches
 */
#[AsCommand(name: 'influxdb:replay-failed')]
class ReplayFailedCommand extends Command
{
    /**
     * The console command signature.
     */
    protected ?string $signature = 'influxdb:replay-failed
        {connection? : The InfluxDB connection, the default one when left out}
        {--limit=100 : The most batches to replay}
    ';

    /**
     * The console command description.
     */
    protected string $description = 'Write the batches an InfluxDB connection\'s failure store kept';

    /**
     * Execute the console command.
     */
    public function handle(InfluxDBManager $manager): int
    {
        $name = $this->argument('connection') ?: $manager->getDefaultConnection();
        $limit = $this->option('limit');

        if (! is_numeric($limit) || (int) $limit < 1 || (float) $limit !== floor((float) $limit)) {
            $this->error('The --limit must be a whole number above zero.');

            return self::FAILURE;
        }

        try {
            $replayed = $manager->replayFailed((string) $name, (int) $limit);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error(sprintf('InfluxDB connection [%s] could not replay its failed batches: %s', $name, $exception->getMessage()));

            return self::FAILURE;
        }

        $this->info(sprintf('Replayed %d %s on InfluxDB connection [%s].', $replayed, $replayed === 1 ? 'batch' : 'batches', $name));

        return self::SUCCESS;
    }
}
