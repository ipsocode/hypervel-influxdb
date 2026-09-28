<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\InfluxQL\Grammars\Concerns;

use InvalidArgumentException;
use Ipsocode\InfluxDB\InfluxQL\Version;
use RuntimeException;

/**
 * Refuses, as the statement compiles, what the connection's InfluxDB version does not run.
 *
 * The Version says which statements each version runs; this is where a
 * grammar turns that into a refusal before anything is sent, with the same
 * message whichever grammar compiles the statement. The server would refuse
 * `SELECT ... INTO` on InfluxDB 2.x and 3, and SLIMIT, SOFFSET and DELETE on
 * 3; and on a 2.x connection that addresses a retention policy, a DELETE
 * could run on another bucket than the one the connection reads.
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
     * InfluxDB 3 implements neither, and refuses both even at 0, the clauses'
     * no-op, so there 0 compiles to nothing and any other value is refused.
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
     * InfluxDB 3 has no DELETE. InfluxDB 2.x runs one on the database's
     * default retention policy only, so it is refused on a connection that
     * addresses a policy (Version::deletesFromDefaultRetentionPolicyOnly()).
     *
     * @throws RuntimeException
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
     * InfluxDB's parser refuses a database or retention policy in a DELETE,
     * which applies to the database the request addresses, so a dotted name
     * is refused; a measurement whose own name has a dot is an Expression.
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
