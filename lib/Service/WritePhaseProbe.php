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
	 * Whether tracing is on, resolved from the flag file once per process.
	 *
	 * @return bool
	 */
	private static function enabled(): bool {
		if (self::$enabled === null) {
			self::$enabled = @file_exists('/tmp/or-trace-write-phases');
		}

		return self::$enabled;

	}//end enabled()

	/**
	 * Milliseconds since the request began, or null when the start is unknown.
	 *
	 * @return float|null
	 */
	private static function elapsedMs(): ?float {
		$start = (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? 0);
		if ($start <= 0.0) {
			return null;
		}

		return ((microtime(true) - $start) * 1000);

	}//end elapsedMs()

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
		if (self::enabled() === false) {
			return;
		}

		self::arm();
		self::$counts[$event] = ((self::$counts[$event] ?? 0) + 1);

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
		if (self::enabled() === false) {
			return;
		}

		self::arm();
		$elapsed = self::elapsedMs();
		if ($elapsed !== null && isset(self::$stamps[$label]) === false) {
			self::$stamps[$label] = $elapsed;
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
		if (self::enabled() === false) {
			return;
		}

		$now = microtime(true);
		if (self::$last !== null && $phase !== 'start') {
			self::$phases[$phase] = ((self::$phases[$phase] ?? 0.0) + (($now - self::$last) * 1000));
		}

		self::$last = $now;

	}//end mark()

	/**
	 * Write the accumulated timeline out and reset it.
	 *
	 * Emits when there is ANYTHING to say. Requiring phases meant read paths
	 * — which produce stamps and counts but no phase marks — flushed
	 * nothing, which is exactly why search and single-read looked
	 * unmeasurable.
	 *
	 * @return void
	 */
	public static function flush(): void {
		if (self::$enabled !== true || self::isEmpty() === true) {
			return;
		}

		$line = self::phaseSegment() . self::stampSegment() . self::opcacheSegment() . self::countSegment();
		@file_put_contents('/tmp/or-write-phases.log', $line . PHP_EOL, (FILE_APPEND | LOCK_EX));

		self::$phases = [];
		self::$counts = [];
		self::$last = null;

	}//end flush()

	/**
	 * Whether nothing has been recorded: no phases, counts or stamps.
	 *
	 * @return bool
	 */
	private static function isEmpty(): bool {
		return empty(self::$phases) === true
			&& empty(self::$counts) === true
			&& empty(self::$stamps) === true;

	}//end isEmpty()

	/**
	 * The total, then every phase, longest first.
	 *
	 * @return string
	 */
	private static function phaseSegment(): string {
		$total = array_sum(self::$phases);
		arsort(self::$phases);

		return sprintf('total=%.0fms', $total) . self::pairs(values: self::$phases, format: '  %s=%.0fms');

	}//end phaseSegment()

	/**
	 * The absolute stamps, earliest first, including the offset of this flush when known.
	 *
	 * @return string Empty when there are no stamps.
	 */
	private static function stampSegment(): string {
		$elapsed = self::elapsedMs();
		if ($elapsed !== null) {
			self::$stamps['flush'] = $elapsed;
		}

		if (empty(self::$stamps) === true) {
			return '';
		}

		asort(self::$stamps);

		return '  [at' . self::pairs(values: self::$stamps, format: ' %s=%.0fms') . ']';

	}//end stampSegment()

	/**
	 * The opcache statistics, when opcache reports them.
	 *
	 * @return string Empty when opcache is absent or reports nothing.
	 */
	private static function opcacheSegment(): string {
		if (function_exists('opcache_get_status') === false) {
			return '';
		}

		$status = @opcache_get_status(false);
		if (is_array($status) === false) {
			return '';
		}

		return self::formatOpcache(status: $status);

	}//end opcacheSegment()

	/**
	 * Format an opcache status; a missing statistic reads `?`, a missing waste figure 0.
	 *
	 * @param array $status The result of opcache_get_status().
	 *
	 * @return string
	 */
	private static function formatOpcache(array $status): string {
		$keys = ['num_cached_scripts', 'max_cached_keys', 'hits', 'misses', 'oom_restarts', 'hash_restarts'];
		$values = array_map(static fn (string $key): mixed => ($status['opcache_statistics'][$key] ?? '?'), $keys);
		$values[] = ($status['memory_usage']['current_wasted_percentage'] ?? 0);

		return sprintf(
			'  [opcache cached=%s/%s hits=%s misses=%s oom_restarts=%s hash_restarts=%s wasted=%.1f%%]',
			...$values
		);

	}//end formatOpcache()

	/**
	 * The event counts, most frequent first.
	 *
	 * @return string Empty when nothing was counted.
	 */
	private static function countSegment(): string {
		if (empty(self::$counts) === true) {
			return '';
		}

		arsort(self::$counts);

		return '  [counts' . self::pairs(values: self::$counts, format: ' %s=%d') . ']';

	}//end countSegment()

	/**
	 * Format every name/value pair with one sprintf format and join them.
	 *
	 * @param array<string,int|float> $values The pairs, in output order.
	 * @param string                  $format A format taking the name, then the value.
	 *
	 * @return string
	 */
	private static function pairs(array $values, string $format): string {
		$out = '';
		foreach ($values as $name => $value) {
			$out .= sprintf($format, $name, $value);
		}

		return $out;

	}//end pairs()
}//end class
