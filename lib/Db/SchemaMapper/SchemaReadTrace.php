<?php

/**
 * SchemaReadTrace: which path issues a schema read.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\SchemaMapper;

use OCP\IAppConfig;

/**
 * Counts schema reads per mapper method and caller, when attribution is enabled.
 *
 * A single object create was measured issuing 5,135 sequential scans of
 * `oc_openregister_schemas` (2026-07-28). The count alone does not say which
 * path is responsible, and the fix differs per path: a cache key that is too
 * specific is a different repair from an uncached sibling method. This records
 * `<mapper method>|<caller signature>` so the answer is measured rather than
 * guessed.
 *
 * Off by default and gated on `openregister perf_trace_schema_reads`, resolved
 * once per process: reading app config on every schema read would itself be one
 * of the reads being counted.
 *
 * @spec openspec/changes/object-write-sub-500ms/specs/object-write-performance/spec.md
 */
final class SchemaReadTrace {

	/**
	 * Read counts by attribution key, flushed at shutdown.
	 *
	 * @var array<string, int>
	 */
	private static array $perfCounts = [];

	/**
	 * Whether attribution is on: null until resolved, then a bool.
	 *
	 * @var boolean|null
	 */
	private static ?bool $perfTrace = null;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App configuration.
	 */
	public function __construct(private readonly IAppConfig $appConfig) {
	}//end __construct()

	/**
	 * Record one schema read against its caller, when attribution is enabled.
	 *
	 * @param string $method The mapper method issuing the read.
	 *
	 * @return void
	 */
	public function record(string $method): void {
		if ($this->enabled() === false) {
			return;
		}

		$key = $method . '|' . self::callerSignature();
		self::$perfCounts[$key] = ((self::$perfCounts[$key] ?? 0) + 1);
	}//end record()

	/**
	 * Whether attribution is on, resolving it on the first read of the process.
	 *
	 * @return bool
	 */
	private function enabled(): bool {
		if (self::$perfTrace === null) {
			self::$perfTrace = ($this->appConfig->getValueString('openregister', 'perf_trace_schema_reads', '') === '1');
			if (self::$perfTrace === true) {
				register_shutdown_function(self::flush(...));
			}
		}

		return self::$perfTrace;
	}//end enabled()

	/**
	 * The four frames past the mapper method: enough to name the responsible
	 * path without paying for a full backtrace on every read.
	 *
	 * @return string
	 */
	private static function callerSignature(): string {
		$frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 7);
		$sig = [];
		foreach (array_slice($frames, 3, 4) as $frame) {
			$sig[] = (($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '?'));
		}

		return implode('<', $sig);
	}//end callerSignature()

	/**
	 * Append the counts to the trace file.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.ErrorControlOperator) The trace file is a
	 * best-effort diagnostic written from a shutdown function; a failure to
	 * write it must never surface as a warning on a real request.
	 */
	private static function flush(): void {
		if (empty(self::$perfCounts) === true) {
			return;
		}

		@file_put_contents(
			'/tmp/or-schema-reads.jsonl',
			json_encode(self::$perfCounts) . PHP_EOL,
			(FILE_APPEND | LOCK_EX)
		);
		self::$perfCounts = [];
	}//end flush()
}//end class
