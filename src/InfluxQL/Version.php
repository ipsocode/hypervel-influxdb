<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\InfluxQL;

use InvalidArgumentException;

/**
 * The InfluxDB major version a connection's server runs, as its `version` names it.
 *
 * It decides which Connection and Grammar a connection's InfluxQL goes through,
 * and its predicates say which statements the server runs.
 *
 * @see docs/configuration.md#choosing-the-server-version
 */
enum Version: string
{
    case V1 = 'v1';
    case V2 = 'v2';
    case V3 = 'v3';

    /**
     * Resolve a connection's configured version: `v1` when none is set, in any case otherwise.
     *
     * @param string $connection the connection's name, for the message
     *
     * @throws InvalidArgumentException when the value names no version this package implements
     */
    public static function resolve(mixed $version, string $connection): self
    {
        if ($version instanceof self) {
            return $version;
        }

        if ($version === null || $version === '') {
            return self::V1;
        }

        $name = is_scalar($version) ? (string) $version : get_debug_type($version);

        return self::tryFrom(strtolower($name)) ?? throw new InvalidArgumentException(sprintf(
            'InfluxDB connection [%s] has an unsupported version [%s]; expected one of %s.',
            $connection,
            $name,
            implode(', ', array_column(self::cases(), 'value')),
        ));
    }

    /**
     * Get the release line the version stands for, such as `InfluxDB 2.x`.
     */
    public function label(): string
    {
        return match ($this) {
            self::V1 => 'InfluxDB 1.x',
            self::V2 => 'InfluxDB 2.x',
            self::V3 => 'InfluxDB 3',
        };
    }

    /**
     * Determine whether a version the server reports on /ping is of this major version.
     *
     * InfluxDB 2.x reports `v2.7.12`, and 1.x and 3 a bare `1.8.10` or `3.11.5`; either spelling matches.
     */
    public function matches(string $serverVersion): bool
    {
        return preg_match('/^v?' . substr($this->value, 1) . '(?:\.|$)/', $serverVersion) === 1;
    }

    /**
     * Determine whether the version runs `SELECT ... INTO`.
     *
     * Only InfluxDB 1.x does; 2.x downsamples with a task, and 3 with its processing engine.
     */
    public function supportsSelectInto(): bool
    {
        return match ($this) {
            self::V1 => true,
            self::V2, self::V3 => false,
        };
    }

    /**
     * Determine whether the version runs SLIMIT and SOFFSET, which limit and skip whole series.
     *
     * InfluxDB 3 implements neither, and refuses both even at 0, their no-op.
     */
    public function supportsSeriesLimits(): bool
    {
        return match ($this) {
            self::V1, self::V2 => true,
            self::V3 => false,
        };
    }

    /**
     * Determine whether the version runs InfluxQL's DELETE.
     *
     * InfluxDB 3 does not; its points go with the table or the database that holds them.
     */
    public function supportsDelete(): bool
    {
        return match ($this) {
            self::V1, self::V2 => true,
            self::V3 => false,
        };
    }

    /**
     * Determine whether the version's DELETE applies to the database's default retention policy alone.
     *
     * InfluxDB 2.x deletes from the bucket mapped as the database's default and
     * ignores the request's retention policy, so a connection that addresses one
     * would delete from another bucket than it reads, or be refused.
     */
    public function deletesFromDefaultRetentionPolicyOnly(): bool
    {
        return match ($this) {
            self::V2 => true,
            self::V1, self::V3 => false,
        };
    }
}
