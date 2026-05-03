<?php

/**
 * OpenRegister PlaceholderResolver
 *
 * Resolves time and session placeholders inside aggregation/calculation
 * annotation values. Shared by the parallel `aggregations-annotation`
 * and `calculations-annotation` changes so both write the same DSL.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Search
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Search;

use DateTimeImmutable;
use DateTimeZone;
use OCP\IUserSession;

/**
 * Resolves placeholder strings to concrete values.
 *
 * Supported placeholders:
 * - `$now`, `$startOfDay`, `$startOfWeek`, `$startOfMonth`, `$startOfYear`
 * - `$currentUser` (uid string, or empty when unauthenticated)
 *
 * Offset arithmetic on dates: `$now-7d`, `$startOfMonth-1` (month),
 * `$startOfYear+1` (year). Suffixes: `d`, `w`, `m`, `y`. Bare integer
 * after a date placeholder defaults to the placeholder's natural unit
 * (`$startOfMonth-1` = one month earlier).
 */
final class PlaceholderResolver
{
    /**
     * Constructor.
     *
     * @param IUserSession $userSession Session resolver for `$currentUser`.
     */
    public function __construct(
        private readonly IUserSession $userSession
    ) {
    }//end __construct()

    /**
     * Resolve a single value. Strings are parsed for placeholders;
     * non-strings are passed through.
     *
     * @param mixed $value The value to resolve (typically a string placeholder).
     *
     * @return mixed Resolved value: DateTimeImmutable for date placeholders,
     *               string for $currentUser, original value otherwise.
     */
    public function resolve(mixed $value): mixed
    {
        if (is_string($value) === false || str_starts_with($value, '$') === false) {
            return $value;
        }

        if ($value === '$currentUser') {
            return ($this->userSession->getUser()?->getUID() ?? '');
        }

        return $this->resolveDate(expr: $value);
    }//end resolve()

    /**
     * Recursively resolve placeholders inside an array.
     *
     * @param array<string, mixed> $values The array whose leaf values may contain placeholders.
     *
     * @return array<string, mixed>
     */
    public function resolveArray(array $values): array
    {
        $resolved = [];
        foreach ($values as $key => $value) {
            if (is_array($value) === true) {
                $resolved[$key] = $this->resolveArray(values: $value);
                continue;
            }

            $resolved[$key] = $this->resolve(value: $value);
        }

        return $resolved;
    }//end resolveArray()

    /**
     * Parse a date-style placeholder + optional offset into a DateTimeImmutable.
     *
     * @param string $expr The raw placeholder expression (e.g. `$now-7d`).
     *
     * @return mixed A DateTimeImmutable for known placeholders; the raw string when unknown.
     */
    private function resolveDate(string $expr): mixed
    {
        // Split into base + optional offset (e.g. `$now-7d`, `$startOfMonth+1`).
        $matched   = preg_match('/^(\$[a-zA-Z]+)([+-]\d+)([dwmy]?)$/', $expr, $m);
        $datebases = ['$now', '$startOfDay', '$startOfWeek', '$startOfMonth', '$startOfYear'];
        if ($matched === 1) {
            $base = $m[1];
            $sign = (int) $m[2];
            $unit = ($m[3] !== '' ? $m[3] : $this->defaultUnitFor(base: $base));
        } else if (in_array($expr, $datebases, true) === true) {
            $base = $expr;
            $sign = 0;
            $unit = $this->defaultUnitFor(base: $expr);
        } else {
            // Unknown placeholder — leave as-is for the caller to surface.
            return $expr;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
        $dt  = match ($base) {
            '$now'          => $now,
            '$startOfDay'   => $now->modify('today'),
            '$startOfWeek'  => $now->modify('monday this week'),
            '$startOfMonth' => $now->modify('first day of this month')->modify('today'),
            '$startOfYear'  => $now->modify('first day of January '.$now->format('Y'))->modify('today'),
            default         => $now,
        };

        if ($sign !== 0) {
            $unitWord = match ($unit) {
                'd'     => 'days',
                'w'     => 'weeks',
                'm'     => 'months',
                'y'     => 'years',
                default => 'days',
            };

            $intervalSpec = sprintf('%s%d %s', ($sign < 0 ? '-' : '+'), abs($sign), $unitWord);
            $dt           = $dt->modify($intervalSpec);
        }

        return $dt;
    }//end resolveDate()

    /**
     * Pick the natural unit suffix for a date placeholder.
     *
     * @param string $base The placeholder base (e.g. `$startOfWeek`).
     *
     * @return string One of `d`, `w`, `m`, `y`.
     */
    private function defaultUnitFor(string $base): string
    {
        return match ($base) {
            '$startOfWeek'  => 'w',
            '$startOfMonth' => 'm',
            '$startOfYear'  => 'y',
            default         => 'd',
        };
    }//end defaultUnitFor()
}//end class
