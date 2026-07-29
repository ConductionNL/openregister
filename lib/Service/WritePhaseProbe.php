<?php

/**
 * Phase timer for the object write path.
 *
 * Answers "where did the 3 seconds go" with measurements rather than
 * inference. `ObjectService::saveObject()` marks its boundaries — prepare +
 * cascade, validate, folder, persist + events, render — and this accumulates
 * the elapsed time between them.
 *
 * Off unless `/tmp/or-trace-write-phases` exists, checked once per process, so
 * it costs a single `file_exists` on the normal path. Deliberately file-gated
 * rather than app-config gated: reading app config is itself a database read,
 * and this instrument exists to count database reads.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/object-write-sub-500ms/specs/object-write-performance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Accumulates elapsed time between named marks in the write path.
 */
final class WritePhaseProbe
{

    /**
     * Whether tracing is on; null until resolved once per process.
     *
     * @var boolean|null
     */
    private static ?bool $enabled = null;

    /**
     * Timestamp of the previous mark, or null when no phase is open.
     *
     * @var float|null
     */
    private static ?float $last = null;

    /**
     * Accumulated milliseconds per phase name.
     *
     * @var array<string, float>
     */
    private static array $phases = [];

    /**
     * Close the phase opened by the previous mark and open a new one.
     *
     * The FIRST mark in a save opens the timeline without recording anything;
     * every later mark records the time since the previous one under its own
     * name. Nested saves (a cascade writes related objects through the same
     * entry point) therefore fold into the same totals, which is what we want:
     * the question is where the request went, not which frame it was in.
     *
     * @param string $phase Name of the phase that just ENDED.
     *
     * @return void
     */
    public static function mark(string $phase): void
    {
        if (self::$enabled === null) {
            self::$enabled = @file_exists('/tmp/or-trace-write-phases');
        }

        if (self::$enabled === false) {
            return;
        }

        $now = microtime(true);
        if (self::$last !== null && $phase !== 'start') {
            if (isset(self::$phases[$phase]) === false) {
                self::$phases[$phase] = 0.0;
            }

            self::$phases[$phase] += (($now - self::$last) * 1000);
        }

        self::$last = $now;

    }//end mark()

    /**
     * Write the accumulated timeline out and reset it.
     *
     * @return void
     */
    public static function flush(): void
    {
        if (self::$enabled !== true || empty(self::$phases) === true) {
            return;
        }

        $total = array_sum(self::$phases);
        $line  = sprintf('total=%.0fms', $total);
        arsort(self::$phases);
        foreach (self::$phases as $name => $ms) {
            $line .= sprintf('  %s=%.0fms', $name, $ms);
        }

        if (function_exists('opcache_get_status') === true) {
            $oc = @opcache_get_status(false);
            if (is_array($oc) === true) {
                $line .= sprintf(
                    '  [opcache cached=%s/%s hits=%s misses=%s oom_restarts=%s hash_restarts=%s wasted=%.1f%%]',
                    ($oc['opcache_statistics']['num_cached_scripts'] ?? '?'),
                    ($oc['opcache_statistics']['max_cached_keys'] ?? '?'),
                    ($oc['opcache_statistics']['hits'] ?? '?'),
                    ($oc['opcache_statistics']['misses'] ?? '?'),
                    ($oc['opcache_statistics']['oom_restarts'] ?? '?'),
                    ($oc['opcache_statistics']['hash_restarts'] ?? '?'),
                    ($oc['memory_usage']['current_wasted_percentage'] ?? 0)
                );
            }
        }

        @file_put_contents('/tmp/or-write-phases.log', $line.PHP_EOL, (FILE_APPEND | LOCK_EX));

        self::$phases = [];
        self::$last   = null;

    }//end flush()
}//end class
