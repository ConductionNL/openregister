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
final class WritePhaseProbe {

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
	 * Per-request event counts, keyed by event name.
	 *
	 * @var array<string, integer>
	 */
	private static array $counts = [];

	/**
	 * Whether the shutdown flush has been armed for this request.
	 *
	 * @var boolean
	 */
	private static bool $armed = false;

	/**
	 * Accumulated milliseconds per phase name.
	 *
	 * @var array<string, float>
	 */
	private static array $phases = [];

	/**
	 * Absolute timestamps, in ms since the request began, keyed by label.
	 *
	 * @var array<string, float>
	 */
	private static array $stamps = [];

	/**
	 * Arm a one-shot flush at request shutdown.
	 *
	 * The write path calls flush() explicitly. Read paths had no such call, so
	 * search and single-read produced no counts at all and were effectively
	 * unmeasured. Registering the flush on first use covers every path without
	 * threading a flush() call through each controller — and a controller that
	 * is added later gets it for free.
	 *
	 * @return void
	 */
	private static function arm(): void {
		if (self::$armed === true) {
			return;
		}

		self::$armed = true;
		register_shutdown_function(
			static function (): void {
				self::flush();
			}
		);

	}//end arm()

	/**
	 * Count one occurrence of a named event in THIS request.
	 *
	 * Exists because counting from the PostgreSQL statement log is not
	 * per-request: a backend is a pooled connection serving consecutive
	 * requests, so bracketing by wall-clock sweeps in unrelated traffic. That
	 * invalidated a set of counts published on 2026-07-30 — an update measured
	 * at 30s under load had a window wide enough to capture 24 other backends'
	 * work. Counting here makes "this request" unambiguous.
	 *
	 * Gated on the same file flag as the phase timer, so it costs one
	 * `file_exists` per process when off.
	 *
	 * @param string $event The thing being counted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-write-at-instance-floor/specs/object-write-performance/spec.md
	 */
	public static function count(string $event): void {
		if (self::$enabled === null) {
			self::$enabled = @file_exists('/tmp/or-trace-write-phases');
		}

		if (self::$enabled === false) {
			return;
		}

		self::arm();

		if (isset(self::$counts[$event]) === false) {
			self::$counts[$event] = 0;
		}

		self::$counts[$event]++;

	}//end count()

	/**
	 * Record how far into the request a named point was reached.
	 *
	 * Separate from {@see mark()} because these are ABSOLUTE offsets from
	 * `REQUEST_TIME_FLOAT`, not durations between marks: the question they
	 * answer is "how much of the request had already elapsed before our code
	 * ran at all", which is how the app-boot floor gets attributed instead of
	 * merely accepted.
	 *
	 * @param string $label The point being stamped.
	 *
	 * @return void
	 */
	public static function stamp(string $label): void {
		if (self::$enabled === null) {
			self::$enabled = @file_exists('/tmp/or-trace-write-phases');
		}

		if (self::$enabled === false) {
			return;
		}

		self::arm();

		$start = (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? 0);
		if ($start <= 0.0) {
			return;
		}

		if (isset(self::$stamps[$label]) === false) {
			self::$stamps[$label] = ((microtime(true) - $start) * 1000);
		}

	}//end stamp()

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
	public static function mark(string $phase): void {
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
	public static function flush(): void {
		// Emit when there is ANYTHING to say. Requiring phases meant read paths
		// — which produce stamps and counts but no phase marks — flushed
		// nothing, which is exactly why search and single-read looked
		// unmeasurable.
		if (self::$enabled !== true) {
			return;
		}

		if (empty(self::$phases) === true
			&& empty(self::$counts) === true
			&& empty(self::$stamps) === true
		) {
			return;
		}

		$total = array_sum(self::$phases);
		$line = sprintf('total=%.0fms', $total);
		arsort(self::$phases);
		foreach (self::$phases as $name => $ms) {
			$line .= sprintf('  %s=%.0fms', $name, $ms);
		}

		$start = (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? 0);
		if ($start > 0.0) {
			self::$stamps['flush'] = ((microtime(true) - $start) * 1000);
		}

		if (empty(self::$stamps) === false) {
			asort(self::$stamps);
			$line .= '  [at';
			foreach (self::$stamps as $label => $ms) {
				$line .= sprintf(' %s=%.0fms', $label, $ms);
			}

			$line .= ']';
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

		if (empty(self::$counts) === false) {
			arsort(self::$counts);
			$line .= '  [counts';
			foreach (self::$counts as $event => $n) {
				$line .= sprintf(' %s=%d', $event, $n);
			}

			$line .= ']';
		}

		@file_put_contents('/tmp/or-write-phases.log', $line . PHP_EOL, (FILE_APPEND | LOCK_EX));

		self::$phases = [];
		self::$counts = [];
		self::$last = null;

	}//end flush()
}//end class
