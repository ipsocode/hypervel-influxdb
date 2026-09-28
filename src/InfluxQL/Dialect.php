<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\InfluxQL;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Stringable;

/**
 * How InfluxQL writes identifiers and literals, and how values are embedded in a statement.
 *
 * The rules every InfluxQL grammar shares, so that a statement reads the same
 * whichever grammar compiled it. It holds no state and knows no grammar's raw
 * Expression type: a grammar embeds its own expressions as written and hands
 * every other value to escape(). Where InfluxQL differs from SQL:
 *
 * - an identifier is double-quoted and a string literal single-quoted, and
 *   both escape their quote and a backslash with a backslash — not by
 *   doubling the quote, as SQL does — and write a literal newline as `\n`;
 * - a trailing `::tag`, `::field` or type cast is syntax, not part of the
 *   column's name, so it stays outside the quotes;
 * - a regular expression is its own literal, `/pattern/`;
 * - a timestamp is an RFC3339 string, and a number is never written with an
 *   exponent, which InfluxQL's scanner does not read.
 */
final class Dialect
{
    /**
     * The format a timestamp is embedded in: RFC3339, in UTC, to the microsecond.
     */
    public const string DATE_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    /**
     * The comparison operators InfluxQL's WHERE clause accepts.
     *
     * @var list<string>
     */
    public const array OPERATORS = ['=', '<', '>', '<=', '>=', '<>', '!=', '=~', '!~'];

    /**
     * The fill() keywords InfluxQL accepts besides a number.
     *
     * @var list<string>
     */
    public const array FILLS = ['none', 'null', 'previous', 'linear'];

    /**
     * A duration literal: an integer followed by one of InfluxQL's units.
     */
    public const string DURATION = '/^-?\d+(?:ns|u|µ|ms|s|m|h|d|w)$/u';

    /**
     * A column followed by its type hint, such as `host::tag`: the name, then the hint.
     */
    private const string TYPE_HINT = '/^(.+)::(tag|field|float|integer|unsigned|string|boolean)$/i';

    /**
     * Quote an identifier: a column, or one segment of a measurement.
     *
     * InfluxQL escapes a double quote inside an identifier with a backslash,
     * not by doubling it, and refuses a literal newline, which is written `\n`.
     * A trailing `::tag`, `::field` or type cast is InfluxQL syntax and stays
     * outside the quotes, and the `*` wildcard is not quoted at all.
     */
    public static function quoteIdentifier(string $value): string
    {
        if ($value === '*') {
            return $value;
        }

        if (preg_match(self::TYPE_HINT, $value, $matches) === 1) {
            return self::quoteIdentifier($matches[1]) . '::' . strtolower($matches[2]);
        }

        return '"' . str_replace(['\\', '"', "\n"], ['\\\\', '\"', '\n'], $value) . '"';
    }

    /**
     * Quote the given string literal, or a comma-separated list of them.
     *
     * InfluxQL escapes a single quote and a backslash with a backslash, and
     * refuses a literal newline inside a string, which is written `\n`.
     *
     * @param array<string>|string $value
     */
    public static function quoteString(string|array $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(self::quoteString(...), $value));
        }

        return "'" . str_replace(['\\', "'", "\n"], ['\\\\', "\\'", '\n'], $value) . "'";
    }

    /**
     * Quote a regular expression as the /pattern/ literal InfluxQL reads.
     *
     * A slash inside the pattern ends the literal early unless escaped, so
     * every one not already preceded by a backslash gets one.
     */
    public static function quoteRegex(Regex $regex): string
    {
        return '/' . preg_replace('~(?<!\\\)/~', '\/', $regex->pattern) . '/';
    }

    /**
     * Format a date as the RFC3339 timestamp InfluxQL reads, to be quoted as a string.
     *
     * The instant is written in UTC with microseconds: InfluxQL parses
     * fractional seconds to nanosecond precision and treats the trailing `Z`
     * as UTC, so no offset arithmetic is left to the server.
     */
    public static function formatDateTime(DateTimeInterface $value): string
    {
        return DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(self::DATE_FORMAT);
    }

    /**
     * Format a float as an InfluxQL number literal.
     *
     * InfluxQL's scanner reads no exponent, so `1.0E+20` and `1.0E-9` have to
     * be written out in full; and a literal without a decimal point is an
     * integer, which overflows above 2^63, so the point is always kept. The
     * digits come from PHP's shortest round-trip representation and are only
     * moved, never rounded.
     *
     * @throws InvalidArgumentException for NAN or INF, which have no literal
     */
    public static function formatFloat(float $value): string
    {
        if (! is_finite($value)) {
            throw new InvalidArgumentException('InfluxQL has no literal for NAN or INF.');
        }

        $literal = var_export($value, true);

        if (! str_contains($literal, 'E')) {
            return $literal;
        }

        [$mantissa, $exponent] = explode('E', $literal);

        $sign = str_starts_with($mantissa, '-') ? '-' : '';

        [$integer, $fraction] = explode('.', ltrim($mantissa, '-'));

        $digits = $integer . $fraction;

        $point = strlen($integer) + (int) $exponent;

        // var_export() writes an exponent only when the point falls outside the
        // digits it prints — below 1e-4, or from 1e17 up — so the point goes
        // either before every digit or after every digit, never between them.
        $number = $point <= 0
            ? '0.' . str_repeat('0', -$point) . $digits
            : $digits . str_repeat('0', $point - strlen($digits)) . '.0';

        $number = rtrim(rtrim($number, '0'), '.');

        return $sign . (str_contains($number, '.') ? $number : $number . '.0');
    }

    /**
     * Escape a value as the InfluxQL literal that stands for it.
     *
     * A raw Expression is the grammar's to embed, as written, before it calls
     * this; one that arrives here is refused like any other object.
     *
     * @throws InvalidArgumentException for a value InfluxQL has no literal
     *                                  for: null, NAN, INF, an array or an
     *                                  arbitrary object
     */
    public static function escape(mixed $value): string
    {
        return match (true) {
            $value instanceof Regex => self::quoteRegex($value),
            $value instanceof DateTimeInterface => self::quoteString(self::formatDateTime($value)),
            $value instanceof Stringable => self::quoteString((string) $value),
            is_null($value) => throw new InvalidArgumentException('InfluxQL has no null literal; a field or tag cannot be compared with null.'),
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => self::formatFloat($value),
            is_string($value) => self::quoteString($value),
            is_array($value) => throw new InvalidArgumentException('An array cannot be embedded in InfluxQL; use whereIn() or whereBetween().'),
            default => throw new InvalidArgumentException(sprintf('A value of type %s cannot be embedded in InfluxQL.', get_debug_type($value))),
        };
    }

    /**
     * Substitute escaped values into a statement, in place of its `?` placeholders.
     *
     * Walks the statement character by character and replaces each `?` that
     * is not inside a string literal or a quoted identifier, honouring the
     * backslash escapes both use. The values are embedded exactly as given,
     * in order and whatever their keys, and are not scanned again; a `?` left
     * without one stays as it is. A `?` inside a raw regular expression
     * literal is not recognised — pass a Regex through the bindings instead.
     *
     * @param array<string> $escaped the literals to embed, each already escaped
     */
    public static function substituteBindings(string $sql, array $escaped): string
    {
        $escaped = array_values($escaped);

        $query = '';

        $index = 0;

        $quote = null;

        for ($i = 0, $length = strlen($sql); $i < $length; ++$i) {
            $char = $sql[$i];

            if ($quote !== null) {
                // Inside a literal: copy verbatim, skipping over an escaped character.
                $query .= $char;

                if ($char === '\\') {
                    $query .= $sql[++$i] ?? '';
                } elseif ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === "'" || $char === '"') {
                // Starting a string literal or a quoted identifier...
                $quote = $char;

                $query .= $char;
            } elseif ($char === '?') {
                // Substitutable binding...
                $query .= $escaped[$index++] ?? '?';
            } else {
                // Normal character...
                $query .= $char;
            }
        }

        return $query;
    }

    /**
     * Strip a trailing type hint from a column: `host::tag` is `host`.
     *
     * InfluxDB returns a column selected with a hint under its name alone.
     * The hint is read as quoteIdentifier() reads it, so a name it would quote
     * whole, such as a lone `::tag`, is returned unchanged.
     */
    public static function stripTypeHint(string $column): string
    {
        return preg_match(self::TYPE_HINT, $column, $matches) === 1 ? $matches[1] : $column;
    }
}
