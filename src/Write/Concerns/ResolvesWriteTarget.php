<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Write\Concerns;

use InvalidArgumentException;

/**
 * Resolves the precision, bucket and org of a write from its arguments and the connection's options.
 */
trait ResolvesWriteTarget
{
    /**
     * Resolve the precision, bucket and org of a write, each from its argument or else the connection's option.
     *
     * The client's own check() lists every option, the token included, in its message, and an
     * unset environment variable reads as an empty string, so an empty one is refused here by name.
     *
     * @return array{string, string, string} the precision, bucket and org
     *
     * @throws InvalidArgumentException when one of them is empty
     */
    protected function resolveWriteTarget(?string $precision, ?string $bucket, ?string $org): array
    {
        $target = [
            'precision' => $precision ?: ($this->options['precision'] ?? null),
            'bucket' => $bucket ?: ($this->options['bucket'] ?? null),
            'org' => $org ?: ($this->options['org'] ?? null),
        ];

        foreach ($target as $option => $value) {
            if (trim((string) $value) === '') {
                throw new InvalidArgumentException("The '{$option}' should be defined as argument or default option.");
            }
        }

        return [(string) $target['precision'], (string) $target['bucket'], (string) $target['org']];
    }
}
