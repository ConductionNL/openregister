<?php

/**
 * WritePhase: the entry points callers outside the Service component use to mark the write and read path.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

/**
 * Marks, stamps and counts points of the write and read path for WritePhaseProbe.
 *
 * The probe keeps its state and its flag-file gate; this class is the entry
 * the controllers, mappers and bootstrap call, so they do not name the probe.
 * Off unless the probe's flag file exists, exactly as before.
 *
 * @spec openspec/changes/object-write-sub-500ms/specs/object-write-performance/spec.md
 */
final class WritePhase {

	/**
	 * Count one occurrence of an event in this request.
	 *
	 * @param string $event The thing being counted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-write-at-instance-floor/specs/object-write-performance/spec.md
	 */
	public static function count(string $event): void {
		WritePhaseProbe::count(event: $event);
	}//end count()

	/**
	 * Record how far into the request a named point was reached.
	 *
	 * @param string $label The point being stamped.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-write-sub-500ms/specs/object-write-performance/spec.md
	 */
	public static function stamp(string $label): void {
		WritePhaseProbe::stamp(label: $label);
	}//end stamp()

	/**
	 * Close the phase opened by the previous mark and open a new one.
	 *
	 * @param string $phase Name of the phase that just ENDED.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-write-sub-500ms/specs/object-write-performance/spec.md
	 */
	public static function mark(string $phase): void {
		WritePhaseProbe::mark(phase: $phase);
	}//end mark()
}//end class
