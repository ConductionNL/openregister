<?php

/**
 * OpenRegister ScheduledFilterEvaluator
 *
 * Evaluates the `filter` block on a scheduled notification trigger
 * against a single object's data. Supports both legacy scalar
 * equality entries and operator-object entries
 * (`equals`, `notEquals`, `withinNext`, `olderThan`).
 *
 * Part of the notification-engine-scheduled-conditions change
 * (Phase 1 — filter operator evaluator).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
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

namespace OCA\OpenRegister\Service\Notification;

use DateInterval;
use DateTimeImmutable;
use Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Evaluates a scheduled-trigger `filter` map against object data.
 *
 * Filter shape is a `field => value-spec` map where `value-spec` is:
 *  - a scalar (string/int/float/bool/null) — equivalent to `{operator: equals, value: <scalar>}`;
 *  - an operator object `{operator: <op>, value: <v>}` where `<op>` is one of:
 *      - `equals`     — strict (`===`) equality;
 *      - `notEquals`  — strict inequality; missing/null field counts as not-equal to any non-null value;
 *      - `withinNext` — field is a date in the half-open window `(now, now + duration]`;
 *      - `olderThan`  — field is a date strictly before `now - duration`.
 *
 * All entries are combined with AND. An empty filter map matches.
 *
 * Fail-closed semantics: unparsable date values in the inspected object
 * (for `withinNext` / `olderThan`) cause that entry to NOT match, plus a
 * debug log. The rule still evaluates the remaining entries — useful so a
 * single bad date does not silently bypass other constraints.
 */
final class ScheduledFilterEvaluator
{

    private LoggerInterface $logger;


    /**
     * Construct an evaluator with an optional logger.
     *
     * The logger is used to emit debug-level diagnostics when a value cannot
     * be parsed; production callers should inject the standard Nextcloud
     * logger, tests may pass a NullLogger.
     *
     * @param LoggerInterface|null $logger Logger for fail-closed diagnostics.
     */
    public function __construct(?LoggerInterface $logger=null)
    {
        $this->logger = ($logger ?? new NullLogger());

    }//end __construct()


    /**
     * Evaluate the filter against the given object data.
     *
     * @param array<string, mixed>  $objectData Flat field map (typically `$object->getObject()`).
     * @param array<string, mixed>  $filter     Filter map per the class docblock.
     * @param DateTimeImmutable     $now        Logical "now" for the entire scan pass.
     *
     * @return bool True when every entry matches, false otherwise.
     */
    public function matches(array $objectData, array $filter, DateTimeImmutable $now): bool
    {
        if (count($filter) === 0) {
            return true;
        }

        foreach ($filter as $field => $spec) {
            if ($this->entryMatches(field: (string) $field, spec: $spec, objectData: $objectData, now: $now) === false) {
                return false;
            }
        }

        return true;

    }//end matches()


    /**
     * Evaluate a single filter entry.
     *
     * @param string               $field      The field name on the object.
     * @param mixed                $spec       Either a scalar (equals shortcut) or an operator object.
     * @param array<string, mixed> $objectData The full object data map.
     * @param DateTimeImmutable    $now        Logical "now" for the entry.
     *
     * @return bool True when the entry matches.
     */
    private function entryMatches(string $field, $spec, array $objectData, DateTimeImmutable $now): bool
    {
        $actual = ($objectData[$field] ?? null);

        // Scalar shortcut — strict equality.
        if (is_array($spec) === false || array_key_exists('operator', $spec) === false) {
            return $actual === $spec;
        }

        $operator = (string) $spec['operator'];
        $value    = ($spec['value'] ?? null);

        switch ($operator) {
            case 'equals':
                return $actual === $value;

            case 'notEquals':
                // Missing/null field satisfies notEquals for any non-null target.
                if ($actual === null && $value !== null) {
                    return true;
                }

                return $actual !== $value;

            case 'withinNext':
                $interval = $this->parseDuration(duration: (string) $value, field: $field, operator: $operator);
                if ($interval === null) {
                    return false;
                }

                $fieldDate = $this->parseDate(value: $actual, field: $field);
                if ($fieldDate === null) {
                    return false;
                }

                $upper = $now->add($interval);

                // Half-open window: (now, now + duration].
                return ($fieldDate > $now && $fieldDate <= $upper);

            case 'olderThan':
                $interval = $this->parseDuration(duration: (string) $value, field: $field, operator: $operator);
                if ($interval === null) {
                    return false;
                }

                $fieldDate = $this->parseDate(value: $actual, field: $field);
                if ($fieldDate === null) {
                    return false;
                }

                $threshold = $now->sub($interval);

                return $fieldDate < $threshold;

            default:
                $this->logger->debug(
                    'ScheduledFilterEvaluator: unknown operator (fail-closed)',
                    ['field' => $field, 'operator' => $operator]
                );
                return false;
        }//end switch

    }//end entryMatches()


    /**
     * Parse an ISO-8601 DateInterval string. Logs + returns null on failure.
     *
     * @param string $duration ISO-8601 duration ("PT24H", "P30D", ...).
     * @param string $field    Field name for diagnostics only.
     * @param string $operator Operator label for diagnostics only.
     *
     * @return DateInterval|null
     */
    private function parseDuration(string $duration, string $field, string $operator): ?DateInterval
    {
        if ($duration === '') {
            $this->logger->debug(
                'ScheduledFilterEvaluator: empty duration (fail-closed)',
                ['field' => $field, 'operator' => $operator]
            );
            return null;
        }

        try {
            return new DateInterval($duration);
        } catch (Exception $e) {
            $this->logger->debug(
                'ScheduledFilterEvaluator: unparsable duration (fail-closed)',
                [
                    'field'    => $field,
                    'operator' => $operator,
                    'value'    => $duration,
                    'error'    => $e->getMessage(),
                ]
            );
            return null;
        }

    }//end parseDuration()


    /**
     * Parse an object-data date value. Accepts strings only; non-string
     * (null, bool, array, etc.) → null + debug log. Empty string → null.
     *
     * @param mixed  $value Raw value from object data.
     * @param string $field Field name for diagnostics only.
     *
     * @return DateTimeImmutable|null
     */
    private function parseDate($value, string $field): ?DateTimeImmutable
    {
        if (is_string($value) === false || $value === '') {
            $this->logger->debug(
                'ScheduledFilterEvaluator: missing or non-string date (fail-closed)',
                ['field' => $field]
            );
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception $e) {
            $this->logger->debug(
                'ScheduledFilterEvaluator: unparsable object date (fail-closed)',
                [
                    'field' => $field,
                    'error' => $e->getMessage(),
                ]
            );
            return null;
        }

    }//end parseDate()


}//end class
