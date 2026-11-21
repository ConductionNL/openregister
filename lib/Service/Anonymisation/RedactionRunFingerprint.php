<?php

/**
 * OpenRegister RedactionRunFingerprint
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Anonymisation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;

/**
 * The fingerprint of what a detection run found.
 *
 * The sorted set of entity type, a hash of the value and the position, so
 * the same findings in another order give the same fingerprint, and any
 * other finding gives another. No value is kept in it.
 */
final class RedactionRunFingerprint {

	/**
	 * The fingerprint of a run's detections.
	 *
	 * @param list<array<string, mixed>> $detections Rows with `entity_type`, `entity_value` and `position_start`.
	 *
	 * @return string A sha256 hex digest.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-2.2
	 */
	public static function of(array $detections): string {
		$lines = [];
		foreach ($detections as $detection) {
			$lines[] = implode(
				'|',
				[
					strtoupper((string)($detection['entity_type'] ?? '')),
					hash('sha256', mb_strtolower(trim((string)($detection['entity_value'] ?? '')))),
					(string)(int)($detection['position_start'] ?? 0),
				]
			);
		}

		$lines = array_values(array_unique($lines));
		sort($lines, SORT_STRING);

		return hash('sha256', implode("\n", $lines));
	}//end of()
}//end class
