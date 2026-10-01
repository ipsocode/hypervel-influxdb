<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\InfluxQL\Grammars\Concerns;

use InvalidArgumentException;
use Ipsocode\InfluxDB\InfluxQL\Version;
use RuntimeException;

/**
 * Refuses, as the statement compiles, what the connection's InfluxDB version does not run.
 *
 * Shared by both InfluxQL grammars, so a refusal reads the same whichever one
 * compiles the statement, and comes before anything is sent.
 *
 * @see docs/configuration.md#choosing-the-server-version
 */
trait RefusesWhatTheVersionLacks
{
    /**
     * Refuse `SELECT ... INTO` on a version that does not implement it.
     *
     * @throws RuntimeException on InfluxDB 2.x and 3
     */
    protected function ensureVersionCompilesInto(Version $version): void
    {
        if (! $version->supportsSelectInto()) {
            throw new RuntimeException(sprintf(
                '%s does not support SELECT ... INTO; downsample with %s instead.',
                $version->label(),
                $version === Version::V2 ? 'a task' : 'the processing engine',
            ));
        }
    }

    /**
     * Compile an SLIMIT or SOFFSET clause, as its keyword is written.
     *
     * InfluxDB 3 refuses both even at 0, their no-op, so a 0 compiles to nothing there.
     *
     * @throws RuntimeException on InfluxDB 3, for any value but 0
     */
    protected function compileSeriesLimit(Version $version, string $keyword, int $value): string
    {
        if ($version->supportsSeriesLimits()) {
            return $keyword . ' ' . $value;
        }

        if ($value !== 0) {
            throw new RuntimeException(sprintf('%s does not support %s.', $version->label(), strtoupper($keyword)));
        }

        return '';
    }

    /**
     * Refuse a DELETE the version does not run on the retention policy the connection addresses.
     *
     * InfluxDB 2.x deletes from the database's default retention policy, whichever the request names.
     *
     * @throws RuntimeException on InfluxDB 3, and on 2.x when the connection addresses a retention policy
     */
    protected function ensureVersionCompilesDelete(Version $version, ?string $retentionPolicy): void
    {
        if (! $version->supportsDelete()) {
            throw new RuntimeException(sprintf('%s does not support DELETE; delete the table or the database instead.', $version->label()));
        }

        if ($retentionPolicy !== null && $version->deletesFromDefaultRetentionPolicyOnly()) {
            throw new RuntimeException(sprintf(
                '%s deletes only from the database\'s default retention policy, not from the connection\'s [%s]; delete through the /api/v2/delete API instead.',
                $version->label(),
                $retentionPolicy,
            ));
        }
    }

    /**
     * Refuse a DELETE that names no measurement, or a qualified one.
     *
     * InfluxDB's parser refuses a database or retention policy in a DELETE.
     *
     * @phpstan-assert !null $from
     *
     * @throws InvalidArgumentException
     */
    protected function ensureDeleteTakesABareMeasurement(mixed $from): void
    {
        if (is_null($from)) {
            throw new InvalidArgumentException('An InfluxQL DELETE needs a measurement; call from() first.');
        }

        if (is_string($from) && str_contains($from, '.')) {
            throw new InvalidArgumentException(sprintf(
                'An InfluxQL DELETE cannot name a database or retention policy, as [%s] does; pass the measurement alone, or as an Expression if the dot is part of its name.',
                $from,
            ));
        }
    }
}
